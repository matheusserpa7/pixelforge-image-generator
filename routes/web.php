<?php

declare(strict_types=1);

use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationImageController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

Route::get('/', fn (): Response => Inertia::render('Welcome'))->name('home');

Route::middleware('auth')->group(function (): void {
    Route::get('dashboard', fn (): Response => Inertia::render('Dashboard'))->name('dashboard');
    Route::get('generations/{generation}/image', GenerationImageController::class)
        ->middleware('can:view,generation')
        ->name('generations.image');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
});

require __DIR__.'/settings.php';
