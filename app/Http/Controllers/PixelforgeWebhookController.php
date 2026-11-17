<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ReceivePixelforgeWebhook;
use App\Enums\WebhookReceipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, ReceivePixelforgeWebhook $receive): JsonResponse
    {
        $receipt = $receive->handle(
            (string) $request->header('Pixelforge-Signature', ''),
            $request->getContent(),
        );

        return match ($receipt) {
            WebhookReceipt::Accepted, WebhookReceipt::Duplicate => response()->json(['received' => true]),
            WebhookReceipt::InvalidSignature => response()->json(['error' => 'invalid_signature'], 401),
            WebhookReceipt::Malformed => response()->json(['error' => 'malformed_payload'], 400),
        };
    }
}
