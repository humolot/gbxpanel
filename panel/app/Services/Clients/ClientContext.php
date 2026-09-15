<?php

namespace App\Services\Clients;

use App\Models\Client;

/** Client signed in to the sub-panel for the current request (set by the client middleware). */
class ClientContext
{
    protected static ?Client $client = null;

    public static function set(?Client $client): void
    {
        static::$client = $client;
    }

    public static function current(): ?Client
    {
        return static::$client;
    }

    public static function id(): ?int
    {
        return static::$client?->id;
    }
}
