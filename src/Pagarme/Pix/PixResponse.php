<?php

declare(strict_types=1);

namespace PagarmeSdk\Pix;

use PagarmeSdk\PaymentStatus;

final class PixResponse
{
    public function __construct(
        public readonly string $tid,
        public readonly PaymentStatus $status,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $pixId,
        public readonly string $qrCode,
        public readonly string $qrCodeText,
        public readonly string $pixCopyPaste,
        public readonly int $expiresInMinutes,
        public readonly ?string $authorizationCode = null,
        public readonly array $gatewayResponse = []
    ) {
    }
}
