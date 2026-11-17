<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class EstimateGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('create', Generation::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['nullable', 'string', 'max:4000'],
            'size' => ['required', Rule::enum(ImageSize::class)],
            'quality' => ['required', Rule::enum(ImageQuality::class)],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['string', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reference_images' => 'reference images',
            'reference_images.*' => 'reference image URL',
        ];
    }

    public function prompt(): string
    {
        return $this->string('prompt')->toString();
    }

    public function size(): ImageSize
    {
        return ImageSize::from($this->string('size')->toString());
    }

    public function quality(): ImageQuality
    {
        return ImageQuality::from($this->string('quality')->toString());
    }

    public function referenceImageCount(): int
    {
        $images = $this->input('reference_images');

        return is_array($images) ? count($images) : 0;
    }
}
