<?php

declare(strict_types=1);

namespace PagarmeSdk\Bank;

use DateTime;
use PagarmeSdk\BillAffiliate;
use PagarmeSdk\Customer;

final class BankRequest
{
    /**
     * @param BillAffiliate[] $affiliates
     */
    public function __construct(
        public float $amount,
        public string $currency,
        public Customer $customer,
        public string $description,
        public DateTime $dueDate,
        public ?string $number = null,
        public array $metadata = [],
        public array $affiliates = []
    ) {
    }
}
