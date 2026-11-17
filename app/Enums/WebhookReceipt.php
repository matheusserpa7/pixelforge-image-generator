<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Result of receiving a webhook delivery.
 */
enum WebhookReceipt: string
{
    case Accepted = 'accepted';
    case Duplicate = 'duplicate';
    case InvalidSignature = 'invalid_signature';
    case Malformed = 'malformed';
}
