<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\GenerationRates;
use App\Data\PixelforgeGeneration;
use App\Data\TokenUsage;
use App\Enums\GenerationStatus;
use App\Enums\SnapshotOutcome;
use App\Jobs\DownloadGenerationImage;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Services\Billing\ActualCost;
use App\Services\Pixelforge\GenerationFailureMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ApplyGenerationSnapshot
{
    public function handle(string $generationId, PixelforgeGeneration $snapshot): SnapshotOutcome
    {
        return DB::transaction(function () use ($generationId, $snapshot): SnapshotOutcome {
            $generation = Generation::query()
                ->whereKey($generationId)
                ->lockForUpdate()
                ->first();

            if ($generation === null) {
                return SnapshotOutcome::Ignored;
            }

            if ($generation->provider_id !== null && $generation->provider_id !== $snapshot->id) {
                return SnapshotOutcome::Ignored;
            }

            if ($generation->status->isFinal()) {
                return SnapshotOutcome::Ignored;
            }

            if ($snapshot->status === GenerationStatus::Succeeded && $snapshot->usage === null) {
                $this->fillNullsOnly($generation, $snapshot);

                return SnapshotOutcome::Incomplete;
            }

            if ($snapshot->status->rank() <= $generation->status->rank()) {
                return $this->fillNullsOnly($generation, $snapshot);
            }

            return match ($snapshot->status) {
                GenerationStatus::Processing => $this->applyProcessing($generation, $snapshot),
                GenerationStatus::Failed => $this->applyFailed($generation, $snapshot),
                GenerationStatus::Succeeded => $this->applySucceeded($generation, $snapshot),
                GenerationStatus::Queued => $this->fillNullsOnly($generation, $snapshot),
            };
        });
    }

    private function fillNullsOnly(Generation $generation, PixelforgeGeneration $snapshot): SnapshotOutcome
    {
        $changed = false;

        if ($generation->provider_id === null) {
            $generation->provider_id = $snapshot->id;
            $changed = true;
        }

        if ($generation->rates === null) {
            $generation->rates = $snapshot->rates->toStoredArray();
            $this->raiseHold($generation, $snapshot->rates);
            $changed = true;
        }

        if ($generation->provider_created_at === null) {
            $generation->provider_created_at = self::toCarbon($snapshot->createdAt);
            $changed = true;
        }

        if ($generation->provider_started_at === null && $snapshot->startedAt !== null) {
            $generation->provider_started_at = self::toCarbon($snapshot->startedAt);
            $changed = true;
        }

        if ($generation->provider_completed_at === null && $snapshot->completedAt !== null) {
            $generation->provider_completed_at = self::toCarbon($snapshot->completedAt);
            $changed = true;
        }

        if ($changed) {
            $generation->save();
        }

        return $changed ? SnapshotOutcome::Applied : SnapshotOutcome::Ignored;
    }

    private function applyProcessing(Generation $generation, PixelforgeGeneration $snapshot): SnapshotOutcome
    {
        $generation->status = GenerationStatus::Processing;
        $generation->provider_id = $snapshot->id;

        if ($generation->rates === null) {
            $this->raiseHold($generation, $snapshot->rates);
        }

        $generation->rates = $snapshot->rates->toStoredArray();
        $this->applyProviderTimestamps($generation, $snapshot);
        $generation->save();

        return SnapshotOutcome::Applied;
    }

    /**
     * Once the provider's own rates are known, the hold rises to the maximum charge at those rates when
     * that is higher than the quoted maximum. It never goes down. The caller saves.
     */
    private function raiseHold(Generation $generation, GenerationRates $rates): void
    {
        if ($generation->maximum_tokens === null) {
            return;
        }

        $maximum = ActualCost::calculate(
            TokenUsage::fromStoredArray($generation->maximum_tokens),
            $rates,
            $generation->markup_percent,
        );

        if ($maximum->charge->nanos > $generation->maximum_charge_nanos) {
            $generation->maximum_provider_cost_nanos = $maximum->providerCost->nanos;
            $generation->maximum_charge_nanos = $maximum->charge->nanos;
        }
    }

    private function applyFailed(Generation $generation, PixelforgeGeneration $snapshot): SnapshotOutcome
    {
        $errorCode = $snapshot->error !== null ? $snapshot->error->code : 'generation_failed';

        $generation->status = GenerationStatus::Failed;
        $generation->provider_id = $snapshot->id;
        $generation->rates = $snapshot->rates->toStoredArray();
        $this->applyProviderTimestamps($generation, $snapshot);
        $generation->error_code = $errorCode;
        $generation->error_message = GenerationFailureMessage::forCode($errorCode);
        $generation->save();

        return SnapshotOutcome::Applied;
    }

    private function applySucceeded(Generation $generation, PixelforgeGeneration $snapshot): SnapshotOutcome
    {
        $usage = $snapshot->usage;

        if (! $usage instanceof TokenUsage) {
            throw new LogicException('Succeeded snapshot without usage should not reach applySucceeded.');
        }

        $markupPercent = $generation->markup_percent;

        $cost = ActualCost::calculate(
            $usage,
            $snapshot->rates,
            $markupPercent,
        );

        LedgerEntry::query()->create([
            'generation_id' => $generation->id,
            'user_id' => $generation->user_id,
            'provider_cost_nanos' => $cost->providerCost->nanos,
            'charge_nanos' => $cost->charge->nanos,
            'markup_percent' => $markupPercent,
        ]);

        $generation->status = GenerationStatus::Succeeded;
        $generation->provider_id = $snapshot->id;
        $generation->provider_cost_nanos = $cost->providerCost->nanos;
        $generation->charge_nanos = $cost->charge->nanos;
        $generation->rates = $snapshot->rates->toStoredArray();
        $generation->usage = $usage->toStoredArray();
        $generation->image_url = $snapshot->imageUrl;
        $generation->image_url_expires_at = self::toCarbon($snapshot->imageUrlExpiresAt);
        $this->applyProviderTimestamps($generation, $snapshot);
        $generation->save();

        DownloadGenerationImage::dispatch($generation->id)->afterCommit();

        return SnapshotOutcome::Applied;
    }

    private function applyProviderTimestamps(Generation $generation, PixelforgeGeneration $snapshot): void
    {
        $generation->provider_created_at = self::toCarbon($snapshot->createdAt);

        if ($snapshot->startedAt !== null) {
            $generation->provider_started_at = self::toCarbon($snapshot->startedAt);
        }

        if ($snapshot->completedAt !== null) {
            $generation->provider_completed_at = self::toCarbon($snapshot->completedAt);
        }
    }

    private static function toCarbon(?CarbonImmutable $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        return Carbon::instance($value);
    }
}
