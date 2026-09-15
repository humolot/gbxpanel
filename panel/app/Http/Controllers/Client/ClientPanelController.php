<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Clients\ClientContext;
use Illuminate\Database\Eloquent\Model;

/** Base of the client sub-panel controllers: every resource must belong to the signed-in client. */
abstract class ClientPanelController extends Controller
{
    protected function client(): Client
    {
        return ClientContext::current() ?? abort(401);
    }

    /** 404 (not 403) for resources of other accounts, so ids cannot be probed. */
    protected function owned(Model $model): Model
    {
        abort_unless((int) $model->getAttribute('client_id') === $this->client()->id, 404);

        return $model;
    }

    /** Limit and quota checks before creating a resource. */
    protected function ensureCanAdd(string $relation, string $limitKey, string $label): ?\Illuminate\Http\JsonResponse
    {
        $client = $this->client();
        if (! $client->package) {
            return $this->fail('Your account has no package. Contact support.');
        }
        if (! $client->canAdd($relation, $limitKey)) {
            return $this->fail("Your package allows {$client->limit($limitKey)} {$label}.");
        }
        if ($reason = app(\App\Services\Clients\ClientManager::class)->blockedReason($client)) {
            return $this->fail($reason);
        }

        return null;
    }
}
