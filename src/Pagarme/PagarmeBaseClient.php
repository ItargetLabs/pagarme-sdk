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
            throw new Exception($exception->getMessage(), (int) $exception->getCode(), $exception);
        }
    }

    protected function createOrder(array $payload): array
    {
        return $this->request('POST', 'orders', ['json' => $payload]);
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

        if ($metadata !== []) {
            $payload['metadata'] = $this->normalizeMetadata($metadata);
        }

        return $payload;
    }

    /** @param array<string, mixed> $metadata */
    protected function normalizeMetadata(array $metadata): array
    {
        $normalized = [];

        foreach ($metadata as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                $normalized[(string) $key] = implode(',', array_map(
                    static fn(mixed $item): string => (string) $item,
                    $value
                ));
                continue;
            }

            if (is_scalar($value)) {
                $normalized[(string) $key] = $value;
                continue;
            }

            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
            if ($encoded !== false) {
                $normalized[(string) $key] = $encoded;
            }
        }

        return $normalized;
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
        ];

        if ($document !== '') {
            $payload['document'] = $document;
            $payload['document_type'] = $customer->documentType ?? $this->resolveDocumentType($document);
            $payload['type'] = $customer->type ?? $this->resolveCustomerType($document);
        }

        return array_filter(
            $payload + $this->buildOptionalCustomerPayload($customer),
            static fn($value) => $value !== null && $value !== ''
        );
    }

    private function resolveDocumentType(string $document): string
    {
        return match (strlen($document)) {
            11 => 'CPF',
            14 => 'CNPJ',
            default => 'PASSPORT',
        };
    }

    private function resolveCustomerType(string $document): string
    {
        return strlen($document) > 11 ? 'company' : 'individual';
    }

    private function buildOptionalCustomerPayload(Customer $customer): array
    {
        $address = null;
        if ($customer->address !== null) {
            $address = $this->buildAddressPayload($customer->address);
        }

        return [
            'phones' => $this->buildPhonePayload($customer->phone),
            'address' => $address,
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

    private function buildAddressPayload(Address $address): ?array
    {
        $line1 = trim($address->number . ', ' . $address->street, " ,");
        $zipCode = preg_replace('/\D/', '', $address->zipCode);
        $state = strtoupper(substr(trim($address->state), 0, 2));
        $city = trim($address->city);

        if ($line1 === '' || $zipCode === '' || $city === '' || $state === '') {
            return null;
        }

        return array_filter([
            'line_1' => $line1,
            'line_2' => $address->complement,
            'zip_code' => $zipCode,
            'city' => $city,
            'state' => $state,
            'country' => 'BR',
        ], static fn($value) => $value !== null && $value !== '');
    }

    private function buildItemPayload(float $amount, string $description, ?string $number): array
    {
        return [
            'amount' => (int) round($amount * 100),
            'description' => $description,
            'quantity' => 1,
            'code' => $number ?: uniqid('item_', true),
        ];
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
