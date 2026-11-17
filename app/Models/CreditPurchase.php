<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditPurchaseKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int|null $user_id
 * @property CreditPurchaseKind $kind
 * @property int $amount_nanos
 * @property string|null $idempotency_key
 * @property Carbon|null $created_at
 * @property-read User|null $user
 */
final class CreditPurchase extends Model
{
    public const ?string UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'kind',
        'amount_nanos',
        'idempotency_key',
    ];

    protected static function booted(): void
    {
        self::updating(function (): void {
            throw new LogicException('Credit purchases are append-only.');
        });

        self::deleting(function (): void {
            throw new LogicException('Credit purchases are append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CreditPurchaseKind::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
