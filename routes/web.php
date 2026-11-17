<?php

declare(strict_types=1);

use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationImageController;
use Illuminate\Support\Facades\Route;

// No landing page: the app is the generations page; guests land on login through `auth`.
Route::redirect('/', '/generations')->name('home');

Route::middleware('auth')->group(function (): void {
    Route::redirect('dashboard', '/generations')->name('dashboard');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('generations/estimate', [GenerationController::class, 'estimate'])
        ->middleware('throttle:generation-estimates')
        ->name('generations.estimate');
    Route::get('generations/{generation}/image', GenerationImageController::class)
        ->middleware('can:view,generation')
        ->name('generations.image');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:generations')
        ->name('generations.store');
});

require __DIR__.'/settings.php';
