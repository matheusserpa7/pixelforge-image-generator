<?php

declare(strict_types=1);

namespace App\Enums;

enum SnapshotOutcome: string
{
    case Applied = 'applied';
    case Ignored = 'ignored';
    case Incomplete = 'incomplete';
}
