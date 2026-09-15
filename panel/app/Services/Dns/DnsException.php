<?php

namespace App\Services\Dns;

/** Error returned by a DNS provider API; the message is safe to show to the user. */
class DnsException extends \RuntimeException {}
