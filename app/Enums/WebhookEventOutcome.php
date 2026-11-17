<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What processing did with a stored webhook event.
 */
enum WebhookEventOutcome: string
{
    case Applied = 'applied';
    case Ignored = 'ignored';
    case Incomplete = 'incomplete';
    case UnknownGeneration = 'unknown_generation';
    case InvalidPayload = 'invalid_payload';
    case NotAGenerationEvent = 'not_a_generation_event';

    public static function fromSnapshot(SnapshotOutcome $outcome): self
    {
        return match ($outcome) {
            SnapshotOutcome::Applied => self::Applied,
            SnapshotOutcome::Ignored => self::Ignored,
            SnapshotOutcome::Incomplete => self::Incomplete,
        };
    }
}
