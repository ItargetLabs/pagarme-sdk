<?php

declare(strict_types=1);

namespace PagarmeSdk;

enum PaymentStatus: string
{
    case APPROVED = 'approved';
    case PENDING = 'pending';
    case CANCELLED = 'cancelled';
    case REJECTED = 'rejected';
    case FAILED = 'failed';
    case WAITING_PAYMENT = 'waiting_payment';
    case CONTESTATION = 'contestation';
    case MONITORING = 'monitoring';
}
