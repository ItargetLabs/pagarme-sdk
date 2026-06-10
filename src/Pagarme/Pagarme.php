<?php

declare(strict_types=1);

namespace PagarmeSdk;

use GuzzleHttp\Client;
use PagarmeSdk\Bank\BankClient;
use PagarmeSdk\Bank\BankRequest;
use PagarmeSdk\Bank\BankResponse;
use PagarmeSdk\Bank\BankStatusResponse;
use PagarmeSdk\CreditCard\CreditCardClient;
use PagarmeSdk\CreditCard\CreditCardRequest;
use PagarmeSdk\CreditCard\CreditCardResponse;
use PagarmeSdk\Pix\PixClient;
use PagarmeSdk\Pix\PixRequest;
use PagarmeSdk\Pix\PixResponse;
use PagarmeSdk\Pix\PixStatusResponse;

final class Pagarme
{
    public function __construct(
        private readonly Store $store,
        private ?Client $httpClient = null
    ) {
    }

    public function getTransactionByToken(string $tokenTransaction): array
    {
        return $this->baseClient()->getTransactionByToken($tokenTransaction);
    }

    public function getTransactionById(string $transactionId): array
    {
        return $this->baseClient()->getTransactionById($transactionId);
    }

    public function createCreditCardPayment(CreditCardRequest $request): CreditCardResponse
    {
        return $this->creditCardClient()->processCreditCardPayment($request);
    }

    public function processInstallmentCreditCardPayment(
        CreditCardRequest $request,
        int $installments
    ): CreditCardResponse {
        return $this->creditCardClient()->processInstallmentPayment($request, $installments);
    }

    public function createPixCharge(PixRequest $request): PixResponse
    {
        return $this->pixClient()->generatePixCharge($request);
    }

    public function generateBank(BankRequest $request): BankResponse
    {
        return $this->bankClient()->generateBank($request);
    }

    public function checkPaymentStatus(string $transactionId): PaymentStatus
    {
        return $this->baseClient()->checkPaymentStatus($transactionId);
    }

    public function getBankData(string $tokenTransaction): BankStatusResponse
    {
        return $this->bankClient()->getBankData($tokenTransaction);
    }

    public function checkPixStatus(string $transactionId): PixStatusResponse
    {
        return $this->pixClient()->checkPixStatus($transactionId);
    }

    public function getPixPayload(string $transactionId): string
    {
        return $this->pixClient()->getPixPayload($transactionId);
    }

    public static function parseSettlementWebhook(array $payload): array
    {
        return PagarmeBaseClient::parseSettlementWebhook($payload);
    }

    public static function parseTransactionLookup(array $lookupResponse, array $fallback = []): array
    {
        return PagarmeBaseClient::parseTransactionLookup($lookupResponse, $fallback);
    }

    private function baseClient(): PagarmeBaseClient
    {
        return new PagarmeBaseClient($this->store, $this->httpClient);
    }

    private function creditCardClient(): CreditCardClient
    {
        return new CreditCardClient($this->store, $this->httpClient);
    }

    private function pixClient(): PixClient
    {
        return new PixClient($this->store, $this->httpClient);
    }

    private function bankClient(): BankClient
    {
        return new BankClient($this->store, $this->httpClient);
    }
}
