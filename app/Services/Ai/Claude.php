<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Minimal Claude Messages API client for structured extraction: one tool, and the answer
 * comes back as that tool's input. Never logs content or the key.
 */
class Claude
{
    private const URL = 'https://api.anthropic.com/v1/messages';

    public function isConfigured(): bool
    {
        return (bool) config('services.anthropic.key');
    }

    /**
     * @param  array  $content  user content blocks (text / image / document)
     * @return array{input: array, model: string, tokens_in: int, tokens_out: int}
     */
    public function extract(string $system, array $content, array $tool, int $maxTokens = 4096): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('AI is not set up yet.');
        }

        $body = [
            'model' => config('services.anthropic.model'),
            'max_tokens' => $maxTokens,
            'system' => $system,
            'tools' => [$tool],
            'tool_choice' => ['type' => 'tool', 'name' => $tool['name']],
            'messages' => [['role' => 'user', 'content' => $content]],
        ];

        $response = $this->send($body);
        // Some models only accept tool_choice "auto": retry once that way.
        if ($response->status() === 400 && str_contains((string) $response->json('error.message'), 'tool_choice')) {
            $body['tool_choice'] = ['type' => 'auto'];
            $body['system'] .= "\n\nAlways answer by calling the {$tool['name']} tool exactly once.";
            $response = $this->send($body);
        }

        if ($response->failed()) {
            Log::warning('claude_request_failed', ['status' => $response->status(), 'type' => $response->json('error.type')]);
            throw new RuntimeException($response->status() === 429 || $response->status() >= 500
                ? 'The AI service is busy. Please try again in a minute.'
                : 'The AI service could not read this request.');
        }

        $block = collect($response->json('content', []))->first(fn ($b) => ($b['type'] ?? null) === 'tool_use' && ($b['name'] ?? null) === $tool['name']);
        if (! $block || ! is_array($block['input'] ?? null)) {
            throw new RuntimeException('The AI did not return a usable answer. Please try again or fill the form yourself.');
        }

        return [
            'input' => $block['input'],
            'model' => (string) $response->json('model', config('services.anthropic.model')),
            'tokens_in' => (int) $response->json('usage.input_tokens', 0),
            'tokens_out' => (int) $response->json('usage.output_tokens', 0),
        ];
    }

    public function costInr(int $in, int $out): float
    {
        $usd = $in / 1e6 * config('services.anthropic.usd_per_mtok_in') + $out / 1e6 * config('services.anthropic.usd_per_mtok_out');

        return round($usd * config('services.anthropic.inr_per_usd'), 2);
    }

    private function send(array $body)
    {
        return Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])->acceptJson()->asJson()->timeout((int) config('services.anthropic.timeout'))->connectTimeout(10)->post(self::URL, $body);
    }
}
