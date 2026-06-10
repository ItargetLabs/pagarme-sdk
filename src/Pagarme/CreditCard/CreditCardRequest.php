<?php

declare(strict_types=1);

namespace PagarmeSdk\CreditCard;

use PagarmeSdk\BillAffiliate;
use PagarmeSdk\Customer;

final class CreditCardRequest
{
    /**
     * @param BillAffiliate[] $affiliates
     */
    public function __construct(
        public float $amount,
        public string $currency,
        public Customer $customer,
        public CreditCard $creditCard,
        public ?int $installments = null,
        public ?string $description = null,
        public ?string $number = null,
        public array $metadata = [],
        public array $affiliates = []
    ) {
    }
}
