<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class FailGeneration
{
    public function handle(string $generationId, string $code, string $message): void
    {
        if ($code === '') {
            throw new InvalidArgumentException('The failure code must not be empty.');
        }

        DB::transaction(function () use ($generationId, $code, $message): void {
            $generation = Generation::query()
                ->whereKey($generationId)
                ->lockForUpdate()
                ->first();

            if ($generation === null || $generation->status->isFinal()) {
                return;
            }

            $generation->status = GenerationStatus::Failed;
            $generation->error_code = $code;
            $generation->error_message = $message;
            $generation->save();
        });
    }
}
