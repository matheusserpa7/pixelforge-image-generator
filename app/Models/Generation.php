<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int|null $user_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property ImageSize $size
 * @property ImageQuality $quality
 * @property list<string> $reference_images
 * @property string|null $provider_id
 * @property int $estimated_provider_cost_nanos
 * @property int $maximum_provider_cost_nanos
 * @property int $estimated_charge_nanos
 * @property int $maximum_charge_nanos
 * @property string $markup_percent
 * @property array<string, int>|null $maximum_tokens
 * @property Carbon|null $stuck_reported_at
 * @property int|null $provider_cost_nanos
 * @property int|null $charge_nanos
 * @property array<string, int>|null $rates
 * @property array<string, int>|null $usage
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $image_url
 * @property Carbon|null $image_url_expires_at
 * @property string|null $image_path
 * @property Carbon|null $provider_created_at
 * @property Carbon|null $provider_started_at
 * @property Carbon|null $provider_completed_at
 * @property int $submit_attempts
 * @property bool $submit_unconfirmed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read LedgerEntry|null $ledgerEntry
 */
#[Fillable([
    'user_id',
    'status',
    'prompt',
    'size',
    'quality',
    'reference_images',
    'estimated_provider_cost_nanos',
    'maximum_provider_cost_nanos',
    'estimated_charge_nanos',
    'maximum_charge_nanos',
    'markup_percent',
    'maximum_tokens',
])]
final class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'reference_images' => '[]',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<LedgerEntry, $this>
     */
    public function ledgerEntry(): HasOne
    {
        return $this->hasOne(LedgerEntry::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeForUser(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => ImageSize::class,
            'quality' => ImageQuality::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'maximum_tokens' => 'array',
            'stuck_reported_at' => 'datetime',
            'submit_unconfirmed' => 'boolean',
            'image_url_expires_at' => 'datetime',
            'provider_created_at' => 'datetime',
            'provider_started_at' => 'datetime',
            'provider_completed_at' => 'datetime',
        ];
    }
}
