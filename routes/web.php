<?php

declare(strict_types=1);

use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

Route::get('/', fn (): Response => Inertia::render('Welcome'))->name('home');

Route::middleware('auth')->group(function (): void {
    Route::get('dashboard', fn (): Response => Inertia::render('Dashboard'))->name('dashboard');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
});

require __DIR__.'/settings.php';
