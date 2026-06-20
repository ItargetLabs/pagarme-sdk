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
            $message = $this->extractErrorMessage($exception);
            if ($this->isGenericPagarmeError($message) && isset($options['json']) && is_array($options['json'])) {
                $message .= ' | ' . $this->summarizePagarmePayload($options['json']);
            }

            throw new Exception($message, (int) $exception->getCode(), $exception);
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

            if (is_scalar($value) || is_array($value)) {
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

    private function extractErrorMessage(GuzzleException $exception): string
    {
        $body = $this->extractResponseBody($exception);
        if ($body !== '') {
            $parsed = $this->parsePagarmeResponseBody($body);
            if ($parsed !== '') {
                return $parsed;
            }
        }

        $bodyFromMessage = $this->extractBodyFromGuzzleMessage($exception->getMessage());
        if ($bodyFromMessage !== '') {
            $parsed = $this->parsePagarmeResponseBody($bodyFromMessage);
            if ($parsed !== '') {
                return $parsed;
            }
        }

        return $exception->getMessage();
    }

    private function extractResponseBody(GuzzleException $exception): string
    {
        if (!method_exists($exception, 'getResponse')) {
            return '';
        }

        $response = $exception->getResponse();
        if ($response === null) {
            return '';
        }

        $stream = $response->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return trim((string) $stream);
    }

    private function extractBodyFromGuzzleMessage(string $message): string
    {
        if (preg_match('/response:\s*\R(.*)\s*$/s', $message, $matches) !== 1) {
            return '';
        }

        return trim($matches[1]);
    }

    private function parsePagarmeResponseBody(string $body): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $this->extractDecodedErrorMessage($decoded);
        }

        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }

        return $body;
    }

    private function isGenericPagarmeError(string $message): bool
    {
        return str_contains($message, 'The request is invalid')
            || str_contains($message, 'Client error:')
            || str_contains($message, 'Erro desconhecido');
    }

    /** @param array<string, mixed> $payload */
    private function summarizePagarmePayload(array $payload): string
    {
        $customer = $payload['customer'] ?? null;
        $payment = $payload['payments'][0] ?? null;
        $summary = [
            'customer' => is_array($customer) ? [
                'name' => $customer['name'] ?? null,
                'email' => $customer['email'] ?? null,
                'document' => $customer['document'] ?? null,
                'document_type' => $customer['document_type'] ?? null,
                'type' => $customer['type'] ?? null,
                'has_address' => isset($customer['address']) && is_array($customer['address']),
            ] : null,
            'amount' => $payload['items'][0]['amount'] ?? null,
            'has_split' => is_array($payment) && isset($payment['split']),
            'boleto' => is_array($payment) ? ($payment['boleto'] ?? null) : null,
        ];

        $encoded = json_encode($summary, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return 'payload summary unavailable';
        }

        return 'request=' . $encoded;
    }

    private function extractDecodedErrorMessage(array $decoded): string
    {
        $details = $this->collectPagarmeErrorDetails($decoded);
        $summary = (string) ($decoded['message'] ?? 'Erro desconhecido');

        if ($details === []) {
            return $summary;
        }

        $detailsText = implode('; ', $details);

        if ($summary === '' || $summary === 'Erro desconhecido' || $summary === 'The request is invalid.') {
            return $detailsText;
        }

        return $summary . ': ' . $detailsText;
    }

    /** @return list<string> */
    private function collectPagarmeErrorDetails(array $decoded): array
    {
        $messages = [];
        $errors = $decoded['errors'] ?? null;

        if (!is_array($errors)) {
            return $messages;
        }

        if (array_is_list($errors)) {
            foreach ($errors as $error) {
                $messages = [...$messages, ...$this->formatPagarmeErrorEntry($error, null)];
            }

            return $messages;
        }

        foreach ($errors as $field => $fieldErrors) {
            if (!is_array($fieldErrors)) {
                if (is_string($fieldErrors) && $fieldErrors !== '') {
                    $messages[] = is_string($field) ? $field . ': ' . $fieldErrors : $fieldErrors;
                }

                continue;
            }

            foreach ($fieldErrors as $error) {
                $messages = [...$messages, ...$this->formatPagarmeErrorEntry($error, is_string($field) ? $field : null)];
            }
        }

        return $messages;
    }

    /** @return list<string> */
    private function formatPagarmeErrorEntry(mixed $error, ?string $field): array
    {
        if (is_string($error)) {
            if ($error === '') {
                return [];
            }

            if ($field !== null && $field !== '') {
                return [$field . ': ' . $error];
            }

            return [$error];
        }

        if (!is_array($error)) {
            return [];
        }

        $message = $error['message'] ?? null;
        if (!is_string($message) || $message === '') {
            return [];
        }

        $parameter = $error['parameter_name'] ?? $error['field'] ?? $field;
        if (is_string($parameter) && $parameter !== '') {
            return [$parameter . ': ' . $message];
        }

        return [$message];
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
