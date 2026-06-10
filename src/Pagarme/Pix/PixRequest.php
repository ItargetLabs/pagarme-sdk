<?php

declare(strict_types=1);

namespace PagarmeSdk\Pix;

use PagarmeSdk\BillAffiliate;
use PagarmeSdk\Customer;

final class PixRequest
{
    /**
     * @param BillAffiliate[] $affiliates
     */
    public function __construct(
        public float $amount,
        public string $currency,
        public Customer $customer,
        public ?string $description = null,
        public ?string $number = null,
        public array $metadata = [],
        public array $affiliates = []
    ) {
    }
}
