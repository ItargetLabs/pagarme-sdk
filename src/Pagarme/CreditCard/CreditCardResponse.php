<?php

declare(strict_types=1);

namespace PagarmeSdk\CreditCard;

use PagarmeSdk\PaymentStatus;

final class CreditCardResponse
{
    public function __construct(
        public readonly string $tid,
        public readonly PaymentStatus $status,
        public readonly float $amount,
        public readonly string $currency,
        public readonly ?string $nsu = null,
        public readonly ?int $installments = null,
        public readonly ?float $installmentAmount = null,
        public readonly ?string $authorizationCode = null,
        public readonly ?string $errorMessage = null,
        public readonly array $gatewayResponse = []
    ) {
    }
}
