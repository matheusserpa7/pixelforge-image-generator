<?php

declare(strict_types=1);

use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Stateless routes for provider webhooks, registered in bootstrap/app.php outside
| the `web` group: no session, no CSRF, and their own `webhooks` rate limiter
| (docs/adr/0008-webhook-verification-and-dedup.md).
|
| The signature is the authentication: see ReceivePixelforgeWebhook.
*/

Route::post('pixelforge', PixelforgeWebhookController::class)->name('pixelforge');
