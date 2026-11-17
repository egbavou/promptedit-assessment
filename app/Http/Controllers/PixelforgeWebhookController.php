<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ApplyGenerationSnapshot;
use App\Models\WebhookEvent;
use App\Pixelforge\Exceptions\InvalidPixelforgePayload;
use App\Pixelforge\GenerationData;
use App\Pixelforge\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

final class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookSignature $signature, ApplyGenerationSnapshot $apply): Response
    {
        $secret = config('services.pixelforge.webhook_secret');
        if (! is_string($secret) || $secret === '') {
            Log::critical('The Pixelforge webhook secret is not configured.');

            return response('', 500);
        }

        $rawBody = $request->getContent();
        if (! $signature->verify((string) $request->header('Pixelforge-Signature'), $rawBody, $secret, now()->getTimestamp())) {
            return response('', 400);
        }

        try {
            $event = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response('', 400);
        }

        if (! is_array($event) || array_is_list($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            return response('', 400);
        }
        if ($event['type'] === 'ping') {
            return response()->noContent();
        }
        if (! in_array($event['type'], ['generation.processing', 'generation.succeeded', 'generation.failed'], true)) {
            Log::info('Ignoring an unknown Pixelforge event type.', ['event_id' => $event['id'], 'type' => $event['type']]);

            return response()->noContent();
        }

        try {
            DB::transaction(function () use ($event, $apply): void {
                $object = $event['data']['object'] ?? null;
                $inserted = WebhookEvent::query()->insertOrIgnore([
                    'id' => $event['id'],
                    'type' => $event['type'],
                    'pixelforge_generation_id' => is_array($object) && is_string($object['id'] ?? null) ? $object['id'] : null,
                    'payload' => json_encode($event, JSON_THROW_ON_ERROR),
                    'received_at' => now(),
                ]);
                if ($inserted === 0) {
                    return;
                }
                if (! is_array($object)) {
                    throw new InvalidPixelforgePayload('The event data.object field must be a generation object.');
                }

                $apply->handle(GenerationData::fromArray($object));
            });
        } catch (Throwable $exception) {
            Log::error('Failed to apply a Pixelforge webhook event.', [
                'event_id' => $event['id'],
                'type' => $event['type'],
                'exception' => $exception,
            ]);

            return response('', 500);
        }

        return response()->noContent();
    }
}
