<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Actions\EstimateGeneration;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Http\Requests\EstimateGenerationRequest;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Http\Resources\MoneyView;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\PricingUnavailable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class GenerationController extends Controller
{
    public function index(#[CurrentUser] User $user): Response
    {
        Gate::authorize('viewAny', Generation::class);

        $generations = Generation::query()
            ->forUser($user)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($generations),
            'options' => [
                'sizes' => [
                    ['value' => ImageSize::Square->value, 'label' => 'Square (1024×1024)'],
                    ['value' => ImageSize::Landscape->value, 'label' => 'Landscape (1536×1024)'],
                    ['value' => ImageSize::Portrait->value, 'label' => 'Portrait (1024×1536)'],
                ],
                'qualities' => [
                    ['value' => ImageQuality::Low->value, 'label' => 'Low'],
                    ['value' => ImageQuality::Medium->value, 'label' => 'Medium'],
                    ['value' => ImageQuality::High->value, 'label' => 'High'],
                ],
            ],
            'maxReferenceImages' => 4,
            'maxPromptLength' => 4000,
        ]);
    }

    public function store(
        StoreGenerationRequest $request,
        CreateGeneration $createGeneration,
        #[CurrentUser] User $user,
    ): RedirectResponse {
        try {
            $generation = $createGeneration->handle(
                $user,
                $request->prompt(),
                $request->size(),
                $request->quality(),
                $request->referenceImages(),
            );
        } catch (PricingUnavailable $exception) {
            return back()->withErrors(['pricing' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Generation queued.']);

        return redirect()
            ->route('generations.index')
            ->with('generation_id', $generation->id);
    }

    public function estimate(EstimateGenerationRequest $request, EstimateGeneration $estimate): JsonResponse
    {
        try {
            $result = $estimate->handle(
                $request->prompt(),
                $request->size(),
                $request->quality(),
                $request->referenceImageCount(),
            );
        } catch (PricingUnavailable $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json([
            'estimated_charge' => MoneyView::of($result->estimatedCharge->nanos),
            'maximum_charge' => MoneyView::of($result->maximumCharge->nanos),
        ]);
    }
}
