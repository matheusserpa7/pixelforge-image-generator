<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Generation
 */
final class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $succeeded = $this->status === GenerationStatus::Succeeded;
        $providerUrlUsable = $this->image_url !== null
            && ($this->image_url_expires_at === null || $this->image_url_expires_at->isFuture());
        $hasImage = $succeeded && ($this->image_path !== null || $providerUrlUsable);

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'is_final' => $this->status->isFinal(),
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_image_count' => count($this->reference_images ?? []),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'estimated_charge' => MoneyView::of($this->estimated_charge_nanos),
            'maximum_charge' => MoneyView::of($this->maximum_charge_nanos),
            'charge' => $succeeded && $this->charge_nanos !== null
                ? MoneyView::of($this->charge_nanos)
                : null,
            'error_message' => $this->status === GenerationStatus::Failed ? $this->error_message : null,
            'image_url' => $hasImage ? route('generations.image', $this->id) : null,
            'image_expired' => $succeeded && $this->image_path === null && ! $providerUrlUsable,
        ];
    }
}
