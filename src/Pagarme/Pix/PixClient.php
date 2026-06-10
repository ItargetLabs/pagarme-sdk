<?php

declare(strict_types=1);

namespace PagarmeSdk\Pix;

use DateTime;
use Exception;
use GuzzleHttp\Client;
use PagarmeSdk\PagarmeBaseClient;
use PagarmeSdk\PaymentStatus;
use PagarmeSdk\Store;

final class PixClient extends PagarmeBaseClient
{
    public function __construct(Store $store, ?Client $httpClient = null)
    {
        parent::__construct($store, $httpClient);
    }

    public function generatePixCharge(PixRequest $request): PixResponse
    {
        $body = $this->createOrder($this->buildPayload($request));
        $charge = self::extractCharge($body);
        $transaction = self::extractLastTransaction($charge);

        return new PixResponse(
            tid: (string) ($charge['id'] ?? $body['id'] ?? ''),
            status: self::mapPagarmeStatus((string) ($charge['status'] ?? 'pending')),
            amount: (float) (($charge['amount'] ?? (int) round($request->amount * 100)) / 100),
            currency: $request->currency,
            pixId: (string) ($transaction['id'] ?? $charge['id'] ?? ''),
            qrCode: (string) ($transaction['qr_code_url'] ?? ''),
            qrCodeText: (string) ($transaction['qr_code'] ?? ''),
            pixCopyPaste: (string) ($transaction['qr_code'] ?? ''),
            expiresInMinutes: $this->calculateExpirationMinutes($transaction['expires_at'] ?? null),
            authorizationCode: null,
            gatewayResponse: $body
        );
    }

    public function generatePixQRCode(string $pixCode): string
    {
        return $pixCode;
    }

    public function checkPixStatus(string $pixId): PixStatusResponse
    {
        $body = $this->getTransactionById($pixId);
        $charge = self::extractCharge($body);
        $transaction = self::extractLastTransaction($charge);

        return new PixStatusResponse(
            status: self::mapPagarmeStatus((string) ($charge['status'] ?? 'pending')),
            tid: (string) ($charge['id'] ?? $pixId),
            nsu: $this->stringOrNull($transaction['acquirer_nsu'] ?? null),
            amount: (float) (($charge['amount'] ?? 0) / 100),
            authorizationCode: null,
            payerSolicitation: null,
            location: $this->stringOrNull($transaction['qr_code_url'] ?? null),
            occurrenceDate: $this->dateOrNull($charge['created_at'] ?? null),
            lowDate: $this->dateOrNull($charge['paid_at'] ?? null),
            pixCopyPaste: $this->stringOrNull($transaction['qr_code'] ?? null),
            rawResponse: $body
        );
    }

    public function getPixPayload(string $pixId): string
    {
        return $this->checkPixStatus($pixId)->pixCopyPaste ?? '';
    }

    public function checkPaymentStatus(string $transactionId): PaymentStatus
    {
        try {
            return $this->checkPixStatus($transactionId)->status;
        } catch (Exception) {
            return PaymentStatus::FAILED;
        }
    }

    private function buildPayload(PixRequest $request): array
    {
        $payment = [
            'payment_method' => 'pix',
            'pix' => [
                'expires_in' => (int) ($request->metadata['expires_in'] ?? 3600),
                'additional_information' => $request->metadata['additional_information'] ?? [],
            ],
        ];

        return $this->buildOrderPayload(
            $request->customer,
            $request->amount,
            $request->currency,
            $request->description ?? 'Pagamento via PIX',
            $request->number,
            $request->metadata,
            $this->withSplit($payment, $request->affiliates)
        );
    }

    private function withSplit(array $payment, array $affiliates): array
    {
        $split = $this->buildSplitPayload($affiliates);
        if ($split !== []) {
            $payment['split'] = $split;
        }

        return $payment;
    }

    private function calculateExpirationMinutes(mixed $expiresAt): int
    {
        if (!is_string($expiresAt) || $expiresAt === '') {
            return 0;
        }

        $diff = (new DateTime())->diff(new DateTime($expiresAt));
        return ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
    }

    private function dateOrNull(mixed $value): ?DateTime
    {
        return is_string($value) && $value !== '' ? new DateTime($value) : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
