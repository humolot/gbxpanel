<?php

namespace App\Services\Api;

use App\Models\ActivityLog;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Outgoing notifications.
 *
 * Every message is signed with the secret of the webhook (HMAC-SHA256 over the exact body), so
 * the receiver can prove it came from this panel. Failed deliveries are kept and retried.
 */
class WebhookManager
{
    public const MAX_ATTEMPTS = 5;

    /** Minutes to wait before attempt 2, 3, 4 and 5. */
    public const BACKOFF = [1, 5, 30, 120];

    /** Queue one delivery per webhook that listens to this event and send them right away. */
    public function dispatch(string $event, array $payload): int
    {
        $sent = 0;
        foreach (Webhook::query()->where('is_active', true)->get() as $webhook) {
            if (! $webhook->listensTo($event)) {
                continue;
            }
            $delivery = WebhookDelivery::query()->create([
                'webhook_id' => $webhook->id,
                'event' => $event,
                'payload' => $payload,
                'status' => 'pending',
            ]);
            $this->send($delivery);
            $sent++;
        }

        return $sent;
    }

    public function send(WebhookDelivery $delivery): bool
    {
        $webhook = $delivery->webhook;
        if (! $webhook || ! $webhook->is_active) {
            $delivery->update(['status' => 'failed', 'error' => 'The webhook was removed or disabled.']);

            return false;
        }

        $body = (string) json_encode([
            'id' => 'evt_'.$delivery->id,
            'event' => $delivery->event,
            'at' => now()->toIso8601String(),
            'panel' => \App\Models\Setting::get('panel_title', 'GBX Panel'),
            'host' => gethostname(),
            'data' => $delivery->payload,
        ], JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $body, $webhook->secret);

        try {
            $response = Http::timeout(15)->withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'GBX-Panel/'.config('gbx.version'),
                'X-GBX-Event' => $delivery->event,
                'X-GBX-Delivery' => (string) $delivery->id,
                'X-GBX-Signature' => 'sha256='.$signature,
            ])->withBody($body, 'application/json')->post($webhook->url);
            $code = $response->status();
            $ok = $response->successful();
            $error = $ok ? null : mb_substr('HTTP '.$code.' '.$response->body(), 0, 500);
        } catch (\Throwable $e) {
            $code = null;
            $ok = false;
            $error = mb_substr($e->getMessage(), 0, 500);
        }

        $attempts = $delivery->attempts + 1;
        $retry = ! $ok && $attempts < self::MAX_ATTEMPTS;
        $delivery->update([
            'status' => $ok ? 'sent' : ($retry ? 'pending' : 'failed'),
            'attempts' => $attempts,
            'response_code' => $code,
            'error' => $error,
            'next_try_at' => $retry ? now()->addMinutes(self::BACKOFF[min($attempts, count(self::BACKOFF)) - 1]) : null,
        ]);
        $webhook->forceFill([
            'last_status' => $code,
            'last_error' => $error,
            'last_at' => now(),
            'failures' => $ok ? 0 : $webhook->failures + 1,
        ])->saveQuietly();

        if (! $ok && ! $retry) {
            ActivityLog::record('api', 'Webhook '.$webhook->name.' gave up after '.$attempts.' attempts', $error);
        }

        return $ok;
    }

    /** Send the deliveries that are waiting for another attempt. */
    public function retryPending(): int
    {
        $done = 0;
        foreach (WebhookDelivery::query()->where('status', 'pending')->where('attempts', '>', 0)->where('next_try_at', '<=', now())->limit(100)->get() as $delivery) {
            $this->send($delivery);
            $done++;
        }

        return $done;
    }

    public function test(Webhook $webhook): WebhookDelivery
    {
        $delivery = WebhookDelivery::query()->create([
            'webhook_id' => $webhook->id,
            'event' => 'ping',
            'payload' => ['message' => 'Test message from the panel', 'random' => Str::random(8)],
            'status' => 'pending',
        ]);
        $this->send($delivery);

        return $delivery->fresh();
    }

    public function purge(int $days = 14): int
    {
        return WebhookDelivery::query()->whereIn('status', ['sent', 'failed'])->where('created_at', '<', now()->subDays($days))->delete();
    }

    /** Short helper used all over the panel. */
    public static function event(string $event, array $payload): void
    {
        try {
            app(self::class)->dispatch($event, $payload);
        } catch (\Throwable $e) {
            ActivityLog::record('api', 'Webhook for '.$event.' not sent', mb_substr($e->getMessage(), 0, 500));
        }
    }
}
