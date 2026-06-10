<?php

declare(strict_types=1);

namespace PagarmeSdk\CreditCard;

final class CreditCard
{
    public function __construct(
        public string $number,
        public string $holderName,
        public string $expirationMonth,
        public string $expirationYear,
        public string $securityCode,
        public ?string $brand = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'holderName' => $this->holderName,
            'expirationMonth' => $this->expirationMonth,
            'expirationYear' => $this->expirationYear,
            'securityCode' => $this->securityCode,
            'brand' => $this->brand,
        ];
    }
}
