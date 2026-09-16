<?php

namespace App\Http\Controllers\Api;

use App\Models\Webhook;
use App\Services\Api\ApiCatalog;
use App\Services\Api\WebhookManager;
use Illuminate\Http\Request;

class WebhookController extends ApiController
{
    public static function resource(Webhook $webhook): array
    {
        return [
            'id' => $webhook->id,
            'name' => $webhook->name,
            'url' => $webhook->url,
            'events' => $webhook->events,
            'is_active' => (bool) $webhook->is_active,
            'last_status' => $webhook->last_status,
            'last_error' => $webhook->last_error,
            'last_at' => $webhook->last_at?->toIso8601String(),
            'failures' => $webhook->failures,
        ];
    }

    public function index()
    {
        return $this->data(Webhook::query()->orderBy('name')->get()->map(fn (Webhook $w) => self::resource($w)), ['events' => array_keys(ApiCatalog::EVENTS)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'url' => ['required', 'url:http,https', 'max:1000'],
            'events' => ['required', 'array', 'min:1', 'max:40'],
            'events.*' => ['string', 'max:60'],
        ]);
        $unknown = array_diff($data['events'], array_merge(['*'], array_keys(ApiCatalog::EVENTS), array_map(fn ($e) => explode('.', $e)[0].'.*', array_keys(ApiCatalog::EVENTS))));
        if ($unknown) {
            return $this->error('unknown_event', 'Unknown event(s): '.implode(', ', $unknown), 422, ['available' => array_keys(ApiCatalog::EVENTS)]);
        }

        $secret = Webhook::newSecret();
        $webhook = Webhook::query()->create($data + ['secret' => $secret, 'is_active' => true]);
        $this->audit('api', 'Created webhook '.$webhook->name, $webhook->url);

        return $this->message('Webhook created. Store the secret: it is shown only once.', self::resource($webhook) + ['secret' => $secret]);
    }

    public function test(Webhook $webhook, WebhookManager $webhooks)
    {
        $delivery = $webhooks->test($webhook);

        return $delivery->status === 'sent'
            ? $this->message('The receiver answered with HTTP '.$delivery->response_code)
            : $this->error('delivery_failed', (string) ($delivery->error ?: 'The receiver did not answer.'), 422);
    }

    public function destroy(Webhook $webhook)
    {
        $name = $webhook->name;
        $webhook->delete();
        $this->audit('api', 'Deleted webhook '.$name);

        return $this->message('Webhook deleted');
    }
}
