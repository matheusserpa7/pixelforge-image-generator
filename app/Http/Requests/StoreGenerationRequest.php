<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('create', Generation::class);
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->has('size')) {
            $merge['size'] = ImageSize::Square->value;
        }

        if (! $this->has('quality')) {
            $merge['quality'] = ImageQuality::Medium->value;
        }

        if (! $this->has('reference_images')) {
            $merge['reference_images'] = [];
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:1', 'max:4000'],
            'size' => ['required', Rule::enum(ImageSize::class)],
            'quality' => ['required', Rule::enum(ImageQuality::class)],
            'reference_images' => ['present', 'array', 'max:4'],
            'reference_images.*' => ['string', 'max:2048', 'url:http,https'],
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

    /**
     * @return list<string>
     */
    public function referenceImages(): array
    {
        $images = $this->input('reference_images', []);

        if (! is_array($images)) {
            return [];
        }

        $urls = [];

        foreach ($images as $url) {
            if (is_string($url)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}
