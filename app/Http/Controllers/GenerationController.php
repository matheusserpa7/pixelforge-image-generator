<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Http\Requests\StoreGenerationRequest;
use App\Models\User;
use App\Services\Billing\PricingUnavailable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class GenerationController extends Controller
{
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

        return redirect()
            ->route('dashboard')
            ->with('generation_id', $generation->id);
    }
}
