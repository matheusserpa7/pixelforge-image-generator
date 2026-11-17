<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Money\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PurchaseCreditsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var list<string> $packs */
        $packs = config('billing.credit_packs_usd');

        return [
            'pack' => ['required', 'string', Rule::in($packs)],
            'idempotency_key' => ['required', 'ulid'],
        ];
    }

    public function amount(): Money
    {
        return Money::fromDecimal($this->string('pack')->toString());
    }

    public function idempotencyKey(): string
    {
        return $this->string('idempotency_key')->toString();
    }
}
