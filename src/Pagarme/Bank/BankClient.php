<?php

declare(strict_types=1);

namespace PagarmeSdk\Bank;

use DateTime;
use Exception;
use GuzzleHttp\Client;
use PagarmeSdk\PagarmeBaseClient;
use PagarmeSdk\Store;

final class BankClient extends PagarmeBaseClient
{
    public function __construct(Store $store, ?Client $httpClient = null)
    {
        parent::__construct($store, $httpClient);
    }

    public function generateBank(BankRequest $request): BankResponse
    {
        if ($request->customer->address === null) {
            throw new Exception('Endereco do cliente obrigatorio para boleto Pagarme');
        }

        $body = $this->createOrder($this->buildPayload($request));
        $charge = self::extractCharge($body);
        $transaction = self::extractLastTransaction($charge);

        return new BankResponse(
            tid: (string) ($charge['id'] ?? $body['id'] ?? ''),
            status: self::mapPagarmeStatus((string) ($charge['status'] ?? 'pending')),
            amount: (float) (($charge['amount'] ?? (int) round($request->amount * 100)) / 100),
            currency: $request->currency,
            digitableLine: (string) ($transaction['line'] ?? ''),
            barCode: (string) ($transaction['barcode'] ?? ''),
            url: (string) ($transaction['pdf'] ?? $transaction['url'] ?? ''),
            hash: (string) ($body['id'] ?? ''),
            authorizationCode: null,
            gatewayResponse: $body
        );
    }

    public function getBankData(string $tokenTransaction): BankStatusResponse
    {
        try {
            return $this->mapBankStatusResponse($this->getTransactionByToken($tokenTransaction), $tokenTransaction);
        } catch (Exception $exception) {
            throw new Exception('Erro ao consultar boleto no Pagar.me: ' . $exception->getMessage());
        }
    }

    public function getBankFile(string $bankId, array $searchParams = []): array
    {
        return [
            'bankId' => $bankId,
            'link' => (string) ($searchParams['externalUrl'] ?? ''),
        ];
    }

    private function mapBankStatusResponse(array $body, ?string $fallbackId = null): BankStatusResponse
    {
        $charge = self::extractCharge($body);
        $transaction = self::extractLastTransaction($charge);

        return new BankStatusResponse(
            status: self::mapPagarmeStatus((string) ($charge['status'] ?? 'pending')),
            transactionId: (string) ($charge['id'] ?? $fallbackId ?? ''),
            amount: (float) (($charge['amount'] ?? 0) / 100),
            feeAmount: 0.0,
            authorizationCode: null,
            nsu: $this->stringOrNull($transaction['id'] ?? null),
            tid: (string) ($charge['id'] ?? ''),
            digitableLine: $this->stringOrNull($transaction['line'] ?? null),
            barCode: $this->stringOrNull($transaction['barcode'] ?? null),
            url: $this->stringOrNull($transaction['pdf'] ?? $transaction['url'] ?? null),
            bankNumber: $this->stringOrNull($transaction['nosso_numero'] ?? null),
            dueDate: $this->dateOrNull($transaction['due_at'] ?? null),
            issueDate: $this->dateOrNull($transaction['created_at'] ?? null),
            occurrenceDate: $this->dateOrNull($charge['created_at'] ?? null),
            lowDate: $this->dateOrNull($charge['paid_at'] ?? null),
            rawResponse: $body
        );
    }

    private function buildPayload(BankRequest $request): array
    {
        $payment = [
            'payment_method' => 'boleto',
            'boleto' => [
                'due_at' => $request->dueDate->format('Y-m-d\TH:i:s\Z'),
                'instructions' => $request->metadata['instructions'] ?? $request->description,
                'document_number' => $request->number,
                'type' => $request->metadata['boleto_type'] ?? 'DM',
            ],
        ];

        return $this->buildOrderPayload(
            $request->customer,
            $request->amount,
            $request->currency,
            $request->description,
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

    private function dateOrNull(mixed $value): ?DateTime
    {
        return is_string($value) && $value !== '' ? new DateTime($value) : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
