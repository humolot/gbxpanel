<?php

namespace App\Services\Databases;

/**
 * Minimal Redis client speaking RESP2 over TCP. Enough for administration: AUTH, SELECT,
 * INFO, SCAN, TYPE/TTL, value reads and writes, CONFIG and BGSAVE.
 */
class RespClient
{
    /** @var resource|null */
    protected $socket = null;

    public function __construct(
        protected string $host = '127.0.0.1',
        protected int $port = 6379,
        protected ?string $password = null,
        protected ?string $username = null,
        protected float $timeout = 3.0,
    ) {}

    public function connect(): static
    {
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, $this->timeout);
        if (! $socket) {
            throw new \RuntimeException("Cannot connect to Redis at {$this->host}:{$this->port}: {$error}");
        }
        stream_set_timeout($socket, 10);
        $this->socket = $socket;
        if ($this->password !== null && $this->password !== '') {
            $this->command(...($this->username ? ['AUTH', $this->username, $this->password] : ['AUTH', $this->password]));
        }

        return $this;
    }

    public function close(): void
    {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function command(string ...$args): mixed
    {
        if (! $this->socket) {
            $this->connect();
        }
        fwrite($this->socket, self::encode($args));

        return $this->read();
    }

    public static function encode(array $args): string
    {
        $out = '*'.count($args)."\r\n";
        foreach ($args as $arg) {
            $arg = (string) $arg;
            $out .= '$'.strlen($arg)."\r\n".$arg."\r\n";
        }

        return $out;
    }

    protected function line(): string
    {
        $line = fgets($this->socket);
        if ($line === false) {
            throw new \RuntimeException('Redis connection closed');
        }

        return substr($line, 0, -2);
    }

    protected function read(): mixed
    {
        $line = $this->line();
        $type = $line[0] ?? '';
        $data = substr($line, 1);

        switch ($type) {
            case '+':
                return $data;
            case '-':
                throw new \RuntimeException('Redis: '.$data);
            case ':':
                return (int) $data;
            case '$':
                $len = (int) $data;
                if ($len < 0) {
                    return null;
                }
                $buf = '';
                while (strlen($buf) < $len + 2) {
                    $chunk = fread($this->socket, $len + 2 - strlen($buf));
                    if ($chunk === false || $chunk === '') {
                        throw new \RuntimeException('Redis connection closed');
                    }
                    $buf .= $chunk;
                }

                return substr($buf, 0, $len);
            case '*':
                $count = (int) $data;
                if ($count < 0) {
                    return null;
                }
                $items = [];
                for ($i = 0; $i < $count; $i++) {
                    $items[] = $this->read();
                }

                return $items;
            default:
                throw new \RuntimeException('Unexpected Redis reply');
        }
    }

    /** INFO parsed into [section => [key => value]]. */
    public static function parseInfo(string $info): array
    {
        $sections = [];
        $current = 'default';
        foreach (preg_split('/\r?\n/', $info) as $line) {
            if ($line === '') {
                continue;
            }
            if ($line[0] === '#') {
                $current = strtolower(trim(substr($line, 1)));

                continue;
            }
            [$k, $v] = array_pad(explode(':', $line, 2), 2, '');
            $sections[$current][$k] = $v;
        }

        return $sections;
    }
}
