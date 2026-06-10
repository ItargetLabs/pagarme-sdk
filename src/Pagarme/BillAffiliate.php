<?php

declare(strict_types=1);

namespace PagarmeSdk;

final class BillAffiliate
{
    public function __construct(
        public readonly string $recipientId,
        public readonly ?int $percentage = null,
        public readonly ?float $commissionAmount = null
    ) {
    }

    public function toArray(): array
    {
        $data = ['recipient_id' => $this->recipientId];

        if ($this->percentage !== null) {
            $data['percentage'] = $this->percentage;
        }

        if ($this->commissionAmount !== null) {
            $data['amount'] = (int) round($this->commissionAmount * 100);
        }

        return $data;
    }
}
