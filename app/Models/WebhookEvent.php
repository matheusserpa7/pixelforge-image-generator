<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WebhookEventOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property string $payload
 * @property string|null $generation_id
 * @property WebhookEventOutcome|null $outcome
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Generation|null $generation
 */
#[Fillable(['event_id', 'type', 'payload'])]
final class WebhookEvent extends Model
{
    /**
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => WebhookEventOutcome::class,
            'processed_at' => 'datetime',
        ];
    }
}
