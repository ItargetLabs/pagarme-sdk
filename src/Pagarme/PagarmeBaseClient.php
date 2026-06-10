<?php

declare(strict_types=1);

namespace PagarmeSdk;

use DateTime;
use DomainException;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class PagarmeBaseClient
{
    private Client $httpClient;

    public function __construct(private readonly Store $store, ?Client $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new Client([
            'base_uri' => $this->store->getEnvironment()->getApiUrl(),
            'timeout' => 30,
            'verify' => true,
        ]);
    }

    public function getTransactionById(string $transactionId): array
    {
        if ($transactionId === '') {
            throw new DomainException('transactionId is required');
        }

        return $this->request('GET', 'orders/' . urlencode($transactionId));
    }

    public function getTransactionByToken(string $tokenTransaction): array
    {
        return $this->getTransactionById($tokenTransaction);
    }

    public function checkPaymentStatus(string $transactionId): PaymentStatus
    {
        return self::mapPagarmeStatus((string) ($this->getCharge($transactionId)['status'] ?? 'pending'));
    }

    public static function mapPagarmeStatus(string $status): PaymentStatus
    {
        return match (strtolower(trim($status))) {
            'paid' => PaymentStatus::APPROVED,
            'canceled', 'cancelled' => PaymentStatus::CANCELLED,
            'failed', 'refused' => PaymentStatus::FAILED,
            'pending' => PaymentStatus::WAITING_PAYMENT,
            'processing', 'authorized' => PaymentStatus::MONITORING,
            default => PaymentStatus::PENDING,
        };
    }

    public static function parseSettlementWebhook(array $payload): array
    {
        $charge = self::extractCharge($payload['data'] ?? $payload);
        $transaction = self::extractLastTransaction($charge);

        return [
            'tid' => (string) ($charge['id'] ?? ''),
            'transactionId' => (string) ($charge['id'] ?? ''),
            'tokenTransaction' => (string) ($payload['data']['id'] ?? $payload['id'] ?? ''),
            'paymentMethodCode' => (string) ($charge['payment_method'] ?? ''),
            'statusCode' => self::normalizeStatus((string) ($charge['status'] ?? '')),
            'lowDate' => self::parseDate((string) ($charge['paid_at'] ?? '')),
            'occurrenceDate' => self::parseDate((string) ($payload['created_at'] ?? '')),
            'authorizationCode' => (string) ($transaction['acquirer_auth_code'] ?? ''),
            'nsu' => (string) ($transaction['acquirer_nsu'] ?? ''),
            'installments' => (int) ($transaction['installments'] ?? 1),
            'rawPayload' => $payload,
        ];
    }

    public static function parseTransactionLookup(array $lookupResponse, array $fallback = []): array
    {
        $charge = self::extractCharge($lookupResponse);
        $transaction = self::extractLastTransaction($charge);

        return [
            ...$fallback,
            'tid' => (string) ($charge['id'] ?? ($fallback['tid'] ?? '')),
            'transactionId' => (string) ($charge['id'] ?? ($fallback['transactionId'] ?? '')),
            'paymentMethodCode' => (string) ($charge['payment_method'] ?? ''),
            'statusCode' => self::normalizeStatus((string) ($charge['status'] ?? '')),
            'lowDate' => self::parseDate((string) ($charge['paid_at'] ?? '')),
            'occurrenceDate' => self::parseDate((string) ($charge['created_at'] ?? '')),
            'authorizationCode' => (string) ($transaction['acquirer_auth_code'] ?? ''),
            'nsu' => (string) ($transaction['acquirer_nsu'] ?? ''),
            'installments' => (int) ($transaction['installments'] ?? 1),
            'apiResponse' => $lookupResponse,
        ];
    }

    protected function request(string $method, string $path, array $options = []): array
    {
        try {
            $response = $this->httpClient->request($method, $path, $this->withHeaders($options));
            $decoded = json_decode((string) $response->getBody(), true);
            return is_array($decoded) ? $decoded : [];
        } catch (GuzzleException $exception) {
            throw new Exception($this->extractErrorMessage($exception), (int) $exception->getCode(), $exception);
        }
    }

    protected function createOrder(array $payload): array
    {
        return $this->request('POST', 'orders', ['json' => $this->removeEmptyValues($payload)]);
    }

    protected function getCharge(string $orderId): array
    {
        $order = $this->getTransactionById($orderId);
        return self::extractCharge($order);
    }

    protected function buildOrderPayload(
        Customer $customer,
        float $amount,
        string $currency,
        string $description,
        ?string $number,
        array $metadata,
        array $payment
    ): array {
        $payload = [
            'code' => $number ?: uniqid('order_', true),
            'closed' => true,
            'customer' => $this->buildCustomerPayload($customer),
            'items' => [$this->buildItemPayload($amount, $description, $number)],
            'payments' => [$payment],
        ];

        $sanitizedMetadata = $this->sanitizeMetadata($metadata);
        if ($sanitizedMetadata !== []) {
            $payload['metadata'] = $sanitizedMetadata;
        }

        return $payload;
    }

    protected function buildSplitPayload(array $affiliates): array
    {
        return array_values(array_map(
            static fn($affiliate) => is_object($affiliate) && method_exists($affiliate, 'toArray')
                ? $affiliate->toArray()
                : (array) $affiliate,
            $affiliates
        ));
    }

    protected static function extractCharge(array $order): array
    {
        $charges = $order['charges'] ?? [];
        return is_array($charges) && is_array($charges[0] ?? null) ? $charges[0] : [];
    }

    protected static function extractLastTransaction(array $charge): array
    {
        $transaction = $charge['last_transaction'] ?? [];
        return is_array($transaction) ? $transaction : [];
    }

    protected static function parseDate(string $value): DateTime
    {
        try {
            return $value === '' ? new DateTime() : new DateTime($value);
        } catch (Exception) {
            return new DateTime();
        }
    }

    private function withHeaders(array $options): array
    {
        $headers = $options['headers'] ?? [];
        unset($options['headers']);

        return $options + ['headers' => $headers + $this->headers()];
    }

    private function headers(): array
    {
        return [
            'accept' => 'application/json',
            'authorization' => 'Basic ' . base64_encode($this->store->getApiKey() . ':'),
            'content-type' => 'application/json',
            'X-PagarMe-User-Agent' => 'pagarme-sdk-php/1.0.0 php/' . PHP_VERSION,
        ];
    }

    private function buildCustomerPayload(Customer $customer): array
    {
        $document = preg_replace('/\D/', '', (string) $customer->document);
        $payload = [
            'code' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'document' => $document,
            'document_type' => $this->resolveDocumentType($document),
            'type' => strlen($document) > 11 ? 'company' : 'individual',
        ];

        return $this->removeEmptyValues(
            $payload + $this->buildOptionalCustomerPayload($customer)
        );
    }

    private function buildOptionalCustomerPayload(Customer $customer): array
    {
        return [
            'phones' => $this->buildPhonePayload($customer->phone),
            'address' => $customer->address ? $this->buildAddressPayload($customer->address) : null,
        ];
    }

    private function buildPhonePayload(?string $phone): ?array
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if ($digits === '') {
            return null;
        }

        $localNumber = substr($digits, -9);
        return ['mobile_phone' => [
            'country_code' => '55',
            'area_code' => substr($digits, -11, 2),
            'number' => $localNumber,
        ]];
    }

    private function buildAddressPayload(Address $address): array
    {
        $neighborhood = substr(trim($address->neighborhood), 0, 64);

        return $this->removeEmptyValues([
            'line_1' => trim($address->street . ', ' . $address->number . ' - ' . $neighborhood),
            'line_2' => $address->complement,
            'zip_code' => preg_replace('/\D/', '', $address->zipCode),
            'city' => $address->city,
            'state' => strtoupper($address->state),
            'country' => 'BR',
        ]);
    }

    private function buildItemPayload(float $amount, string $description, ?string $number): array
    {
        $normalizedDescription = substr(
            preg_replace('/[^A-Za-z0-9 ]/', '', $description) ?? '',
            0,
            100
        );

        return [
            'amount' => (int) round($amount * 100),
            'description' => $normalizedDescription !== '' ? $normalizedDescription : 'Pagamento',
            'quantity' => 1,
            'code' => $number ?: uniqid('item_', true),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, string>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $sanitized = [];

        foreach ($metadata as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_scalar($value)) {
                $sanitized[(string) $key] = (string) $value;
                continue;
            }

            if (is_array($value)) {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
                if (is_string($encoded)) {
                    $sanitized[(string) $key] = $encoded;
                }
            }
        }

        return $sanitized;
    }

    private function resolveDocumentType(string $document): string
    {
        if (strlen($document) > 11) {
            return 'CNPJ';
        }

        if (strlen($document) === 11) {
            return 'CPF';
        }

        return 'PASSPORT';
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function removeEmptyValues(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_array($value)) {
                $nested = $this->removeEmptyValues($value);
                if ($nested !== []) {
                    $result[$key] = $nested;
                }
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function extractErrorMessage(GuzzleException $exception): string
    {
        if (!method_exists($exception, 'getResponse') || !$exception->getResponse()) {
            return $exception->getMessage();
        }

        $decoded = json_decode((string) $exception->getResponse()->getBody(), true);
        return is_array($decoded) ? $this->extractDecodedErrorMessage($decoded) : $exception->getMessage();
    }

    private function extractDecodedErrorMessage(array $decoded): string
    {
        $messages = [];
        $errors = $decoded['errors'] ?? null;

        if (is_array($errors)) {
            foreach ($errors as $field => $issues) {
                if (!is_array($issues)) {
                    continue;
                }

                if (isset($issues['message']) && is_string($issues['message'])) {
                    $messages[] = (string) $issues['message'];
                    continue;
                }

                foreach ($issues as $issue) {
                    if (!is_string($issue) || $issue === '') {
                        continue;
                    }

                    $messages[] = is_string($field) ? "{$field}: {$issue}" : $issue;
                }
            }
        }

        if ($messages !== []) {
            return implode(' | ', $messages);
        }

        return (string) ($decoded['message'] ?? 'Erro desconhecido');
    }

    private static function normalizeStatus(string $status): string
    {
        return match (self::mapPagarmeStatus($status)) {
            PaymentStatus::APPROVED => 'paid',
            PaymentStatus::CANCELLED, PaymentStatus::FAILED, PaymentStatus::REJECTED => 'cancelled',
            default => 'pending',
        };
    }
}
