<?php

declare(strict_types=1);

namespace PagarmeSdk\Bank;

use DateTime;
use PagarmeSdk\PaymentStatus;

final class BankStatusResponse
{
    public function __construct(
        public readonly PaymentStatus $status,
        public readonly string $transactionId,
        public readonly float $amount,
        public readonly float $feeAmount = 0.0,
        public readonly ?string $authorizationCode = null,
        public readonly ?string $nsu = null,
        public readonly ?string $tid = null,
        public readonly ?string $digitableLine = null,
        public readonly ?string $barCode = null,
        public readonly ?string $url = null,
        public readonly ?string $bankNumber = null,
        public readonly ?DateTime $dueDate = null,
        public readonly ?DateTime $issueDate = null,
        public readonly ?DateTime $occurrenceDate = null,
        public readonly ?DateTime $lowDate = null,
        public readonly array $rawResponse = []
    ) {
    }

    public function isCompleted(): bool
    {
        return $this->status === PaymentStatus::APPROVED;
    }
}
