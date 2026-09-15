<?php

namespace App\Services;

class ShellResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $error,
        public readonly string $command = '',
    ) {}

    public function ok(): bool
    {
        return $this->exitCode === 0;
    }

    public function failed(): bool
    {
        return ! $this->ok();
    }

    public function message(): string
    {
        $text = trim($this->error) !== '' ? $this->error : $this->output;

        return trim(mb_substr($text, -2000));
    }

    public function lines(): array
    {
        return array_values(array_filter(explode("\n", trim($this->output)), fn ($l) => $l !== ''));
    }

    public function throw(string $prefix = 'Command failed'): static
    {
        if ($this->failed()) {
            throw new \RuntimeException($prefix.': '.($this->message() ?: 'exit code '.$this->exitCode));
        }

        return $this;
    }
}
