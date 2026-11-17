<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CostEstimator;
use App\Services\Billing\InsufficientCredits;
use Illuminate\Support\Facades\DB;

final class CreateGeneration
{
    public function __construct(
        private readonly GetCachedPricing $getCachedPricing,
        private readonly GetCreditBalance $getCreditBalance,
    ) {}

    /**
     * @param  array<int|string, string>  $referenceImages
     */
    public function handle(
        User $user,
        string $prompt,
        ImageSize $size,
        ImageQuality $quality,
        array $referenceImages,
    ): Generation {
        $pricing = $this->getCachedPricing->handle();

        $markup = config()->string('billing.markup_percent');

        $estimate = CostEstimator::estimate(
            $pricing,
            $prompt,
            $size,
            $quality,
            count($referenceImages),
            $markup,
        );

        return DB::transaction(function () use ($user, $prompt, $size, $quality, $referenceImages, $estimate, $markup): Generation {
            // Serialize concurrent creates for this user so two submits cannot spend the same credit.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $available = $this->getCreditBalance->handle($user)->available();

            if ($estimate->maximumCharge->nanos > $available->nanos) {
                throw new InsufficientCredits($estimate->maximumCharge, $available);
            }

            $generation = Generation::query()->create([
                'user_id' => $user->id,
                'status' => GenerationStatus::Queued,
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => array_values($referenceImages),
                'estimated_provider_cost_nanos' => $estimate->estimatedProviderCost->nanos,
                'maximum_provider_cost_nanos' => $estimate->maximumProviderCost->nanos,
                'estimated_charge_nanos' => $estimate->estimatedCharge->nanos,
                'maximum_charge_nanos' => $estimate->maximumCharge->nanos,
                'markup_percent' => $markup,
                'maximum_tokens' => $estimate->maximumTokens->toStoredArray(),
            ]);

            SubmitGeneration::dispatch($generation->id)->afterCommit();

            return $generation;
        });
    }
}
