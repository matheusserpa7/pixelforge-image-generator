<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class AdminReportRequest extends FormRequest
{
    public const int MAX_DAYS = 92;

    public const int DEFAULT_DAYS = 30;

    public function authorize(): bool
    {
        return $this->user()?->can('admin') === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $today = CarbonImmutable::now('UTC')->startOfDay();
                $end = $this->end();
                $start = $this->start();

                if ($end->greaterThan($today)) {
                    $validator->errors()->add('end', 'The end date cannot be in the future.');
                } elseif ($start->greaterThan($end)) {
                    $validator->errors()->add('start', 'The start date must be on or before the end date.');
                } elseif ((int) $start->diffInDays($end) + 1 > self::MAX_DAYS) {
                    $validator->errors()->add('end', 'The range cannot be longer than '.self::MAX_DAYS.' days.');
                }
            },
        ];
    }

    public function end(): CarbonImmutable
    {
        return $this->day('end') ?? CarbonImmutable::now('UTC')->startOfDay();
    }

    public function start(): CarbonImmutable
    {
        return $this->day('start') ?? $this->end()->subDays(self::DEFAULT_DAYS - 1);
    }

    private function day(string $key): ?CarbonImmutable
    {
        $value = $this->input($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

        return $date;
    }
}
