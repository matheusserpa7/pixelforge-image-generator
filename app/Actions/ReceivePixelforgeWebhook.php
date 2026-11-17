<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\WebhookReceipt;
use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

final class ReceivePixelforgeWebhook
{
    public function handle(string $signatureHeader, string $rawBody): WebhookReceipt
    {
        $configured = config('services.pixelforge.webhook_secret');
        $secret = is_string($configured) ? $configured : '';

        if ($secret === '') {
            Log::error('Pixelforge webhook secret is not configured.');
        }

        if (! WebhookSignature::isValid($signatureHeader, $rawBody, $secret, self::tolerance(), now()->getTimestamp())) {
            return $this->refuse(WebhookReceipt::InvalidSignature);
        }

        $decoded = json_decode($rawBody, true);
        $eventId = is_array($decoded) ? ($decoded['id'] ?? null) : null;
        $type = is_array($decoded) ? ($decoded['type'] ?? null) : null;

        if (
            ! is_string($eventId) || $eventId === '' || strlen($eventId) > 255
            || ! is_string($type) || $type === '' || strlen($type) > 64
        ) {
            return $this->refuse(WebhookReceipt::Malformed);
        }

        try {
            $event = WebhookEvent::query()->create([
                'event_id' => $eventId,
                'type' => $type,
                'payload' => $rawBody,
            ]);
        } catch (UniqueConstraintViolationException) {
            Log::info('Pixelforge webhook received.', [
                'event_id' => $eventId,
                'type' => $type,
                'receipt' => WebhookReceipt::Duplicate->value,
            ]);

            return WebhookReceipt::Duplicate;
        }

        ProcessWebhookEvent::dispatch($event->id);

        Log::info('Pixelforge webhook received.', [
            'event_id' => $eventId,
            'type' => $type,
            'receipt' => WebhookReceipt::Accepted->value,
        ]);

        return WebhookReceipt::Accepted;
    }

    private function refuse(WebhookReceipt $receipt): WebhookReceipt
    {
        Log::warning('Pixelforge webhook received.', ['receipt' => $receipt->value]);

        return $receipt;
    }

    private static function tolerance(): int
    {
        $value = config('services.pixelforge.webhook_tolerance');

        return is_numeric($value) && (int) $value >= 1 ? (int) $value : 300;
    }
}
