<?php

namespace App\Services\Ai;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Minimal client for the Venice AI chat completions endpoint
 * (OpenAI-compatible, see https://api.venice.ai/api/v1).
 */
class VeniceClient
{
    public function apiKey(): ?string
    {
        return Setting::secret('ai_api_key') ?: config('ai.api_key');
    }

    public function configured(): bool
    {
        return (bool) $this->apiKey();
    }

    /**
     * @return array{message: array, finish_reason: ?string, usage: array, cost: ?array}
     */
    public function chat(string $model, array $messages, array $tools = [], array $options = []): array
    {
        $key = $this->apiKey();
        if (! $key) {
            throw new \RuntimeException('Configure your Venice AI API key in Settings > AI first.');
        }

        $payload = array_filter([
            'model' => $model,
            'messages' => $messages,
            'tools' => $tools ?: null,
            'tool_choice' => $tools ? 'auto' : null,
            'parallel_tool_calls' => $tools ? true : null,
            'max_completion_tokens' => $options['max_tokens'] ?? 8192,
            'temperature' => $options['temperature'] ?? null,
            'reasoning_effort' => $options['reasoning_effort'] ?? null,
            'prompt_cache_key' => $options['cache_key'] ?? null,
            'stream' => false,
        ], fn ($v) => $v !== null);

        // Never mix Venice's own system prompt with the panel instructions.
        $payload['venice_parameters'] = [
            'include_venice_system_prompt' => false,
            'strip_thinking_response' => true,
            'enable_web_search' => 'off',
        ];

        $response = Http::withToken($key)
            ->acceptJson()
            ->timeout((int) config('ai.timeout', 300))
            ->connectTimeout(15)
            ->post(rtrim(config('ai.base_url'), '/').'/chat/completions', $payload);

        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->json('error') ?? $response->json('message') ?? $response->body();
            if (is_array($error)) {
                $error = json_encode($error);
            }
            $hint = match ($response->status()) {
                401 => 'Invalid API key or the model requires a Pro account.',
                402 => 'Insufficient Venice balance.',
                429 => 'Rate limit reached, try again in a moment.',
                503 => 'The model is at capacity, try another model.',
                default => null,
            };
            throw new \RuntimeException('Venice AI error '.$response->status().': '.($hint ?? mb_substr((string) $error, 0, 400)));
        }

        $choice = $response->json('choices.0');
        if (! is_array($choice) || ! isset($choice['message'])) {
            throw new \RuntimeException('Venice AI returned an empty response.');
        }

        return [
            'message' => $choice['message'],
            'finish_reason' => $choice['finish_reason'] ?? null,
            'usage' => $response->json('usage') ?? [],
            'cost' => $response->json('cost'),
        ];
    }

    /** Ask the vision model to read an image (screenshot, error dialog, chart...). */
    public function describeImages(array $dataUrls, string $question): string
    {
        $content = [['type' => 'text', 'text' => "The user attached the image(s) below while asking a Linux server administrator assistant:\n\"{$question}\"\n\nDescribe precisely what is shown. Transcribe every visible text, error message, command, path, version number and log line exactly as written. Mention the application or screen type. Do not give advice, only describe."]];
        foreach ($dataUrls as $url) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
        }

        $result = $this->chat(Setting::get('ai_vision_model', config('ai.vision_model')),[['role' => 'user', 'content' => $content]], [], ['max_tokens' => 4096, 'temperature' => 0.1]);

        return self::text($result['message']['content'] ?? '');
    }

    /** Message content may be a string or an array of content parts. */
    public static function text(mixed $content): string
    {
        if (is_array($content)) {
            $content = implode("\n", array_map(fn ($part) => is_array($part) ? ($part['text'] ?? '') : (string) $part, $content));
        }

        // some reasoning models still leak <think> blocks
        return trim(preg_replace('/<think>.*?<\/think>/s', '', (string) $content));
    }
}
