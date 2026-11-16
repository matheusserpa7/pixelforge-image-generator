<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $generation_id
 * @property int|null $user_id
 * @property int $provider_cost_nanos
 * @property int $charge_nanos
 * @property string $markup_percent
 * @property Carbon|null $created_at
 * @property-read Generation $generation
 * @property-read User|null $user
 */
final class LedgerEntry extends Model
{
    public const ?string UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'generation_id',
        'user_id',
        'provider_cost_nanos',
        'charge_nanos',
        'markup_percent',
    ];

    protected static function booted(): void
    {
        self::updating(function (): void {
            throw new LogicException('Ledger entries are append-only.');
        });

        self::deleting(function (): void {
            throw new LogicException('Ledger entries are append-only.');
        });
    }

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
