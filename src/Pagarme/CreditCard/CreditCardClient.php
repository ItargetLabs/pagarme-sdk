<?php

declare(strict_types=1);

namespace PagarmeSdk\CreditCard;

use Exception;
use GuzzleHttp\Client;
use PagarmeSdk\PagarmeBaseClient;
use PagarmeSdk\PaymentStatus;
use PagarmeSdk\Store;

final class CreditCardClient extends PagarmeBaseClient
{
    public function __construct(Store $store, ?Client $httpClient = null)
    {
        parent::__construct($store, $httpClient);
    }

    public function processPayment(CreditCardRequest $request): CreditCardResponse
    {
        return $this->processCreditCardPayment($request);
    }

    public function processCreditCardPayment(CreditCardRequest $request): CreditCardResponse
    {
        $body = $this->createOrder($this->buildPayload($request));
        $charge = self::extractCharge($body);
        $transaction = self::extractLastTransaction($charge);
        $installments = (int) ($transaction['installments'] ?? $request->installments ?? 1);
        $amount = (float) (($charge['amount'] ?? (int) round($request->amount * 100)) / 100);

        return new CreditCardResponse(
            tid: (string) ($charge['id'] ?? $body['id'] ?? ''),
            status: self::mapPagarmeStatus((string) ($charge['status'] ?? 'pending')),
            amount: $amount,
            currency: $request->currency,
            nsu: $this->stringOrNull($transaction['acquirer_nsu'] ?? null),
            installments: $installments > 1 ? $installments : null,
            installmentAmount: $installments > 1 ? $amount / $installments : null,
            authorizationCode: $this->stringOrNull($transaction['acquirer_auth_code'] ?? null),
            errorMessage: $this->stringOrNull($transaction['gateway_response']['errors'][0]['message'] ?? null),
            gatewayResponse: $body
        );
    }

    public function processInstallmentPayment(CreditCardRequest $request, int $installments): CreditCardResponse
    {
        return $this->processCreditCardPayment(new CreditCardRequest(
            amount: $request->amount,
            currency: $request->currency,
            customer: $request->customer,
            creditCard: $request->creditCard,
            installments: $installments,
            description: $request->description,
            number: $request->number,
            metadata: $request->metadata,
            affiliates: $request->affiliates
        ));
    }

    public function checkPaymentStatus(string $transactionId): PaymentStatus
    {
        try {
            return parent::checkPaymentStatus($transactionId);
        } catch (Exception) {
            return PaymentStatus::FAILED;
        }
    }

    public function getAcceptedCardBrands(): array
    {
        return ['visa', 'mastercard', 'amex', 'elo', 'hipercard', 'diners'];
    }

    public function getMaxInstallments(): int
    {
        return 12;
    }

    private function buildPayload(CreditCardRequest $request): array
    {
        $payment = [
            'payment_method' => 'credit_card',
            'credit_card' => [
                'installments' => $request->installments ?? 1,
                'statement_descriptor' => $request->metadata['statement_descriptor'] ?? null,
                'card' => $this->buildCardPayload($request),
            ],
        ];

        return $this->buildOrderPayload(
            $request->customer,
            $request->amount,
            $request->currency,
            $request->description ?? 'Pagamento com cartao de credito',
            $request->number,
            $request->metadata,
            $this->withSplit($payment, $request->affiliates)
        );
    }

    private function buildCardPayload(CreditCardRequest $request): array
    {
        return [
            'number' => $request->creditCard->number,
            'holder_name' => $request->creditCard->holderName,
            'exp_month' => (int) $request->creditCard->expirationMonth,
            'exp_year' => (int) $request->creditCard->expirationYear,
            'cvv' => $request->creditCard->securityCode,
        ];
    }

    private function withSplit(array $payment, array $affiliates): array
    {
        $split = $this->buildSplitPayload($affiliates);
        if ($split !== []) {
            $payment['split'] = $split;
        }

        return $payment;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
