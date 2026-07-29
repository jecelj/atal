<?php

namespace App\Services;

use App\Settings\OpenAiSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranslationService
{
    private const DEFAULT_LOW_COST_MODEL = 'gpt-4o-mini-2024-07-18';
    private const MODEL_PRICES = [
        'gpt-4o-mini' => ['input' => 0.15, 'cached_input' => 0.075, 'output' => 0.60],
        'gpt-5.6-luna' => ['input' => 1.00, 'cached_input' => 0.10, 'output' => 6.00],
        'gpt-5.6-terra' => ['input' => 2.50, 'cached_input' => 0.25, 'output' => 15.00],
        'gpt-5.6-sol' => ['input' => 5.00, 'cached_input' => 0.50, 'output' => 30.00],
    ];

    protected OpenAiSettings $settings;

    public function __construct(OpenAiSettings $settings)
    {
        $this->settings = $settings;
    }

    protected function withGpt56Reasoning(array $payload, string $model): array
    {
        if (str_starts_with($model, 'gpt-5.6')) {
            $payload['reasoning_effort'] = 'none';
        }

        return $payload;
    }

    protected function logOpenAiUsage(string $operation, string $model, array $body): void
    {
        $usage = $body['usage'] ?? null;

        if (!is_array($usage)) {
            return;
        }

        $inputTokens = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
        $cachedInputTokens = (int) (
            data_get($usage, 'prompt_tokens_details.cached_tokens')
            ?? data_get($usage, 'input_tokens_details.cached_tokens')
            ?? 0
        );

        $context = [
            'operation' => $operation,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'cached_input_tokens' => $cachedInputTokens,
            'output_tokens' => $outputTokens,
        ];

        foreach (self::MODEL_PRICES as $prefix => $prices) {
            if (!str_starts_with($model, $prefix)) {
                continue;
            }

            $uncachedInputTokens = max(0, $inputTokens - $cachedInputTokens);
            $context['estimated_cost_usd'] = round(
                (($uncachedInputTokens * $prices['input']) + ($cachedInputTokens * $prices['cached_input']) + ($outputTokens * $prices['output'])) / 1_000_000,
                6,
            );

            break;
        }

        Log::info('OpenAI usage', $context);
    }

    /**
     * Translate text using OpenAI API
     *
     * @param string $text Text to translate
     * @param string $targetLanguage Target language code
     * @param string $sourceLanguage Source language code (default: 'en')
     * @param string|null $context Additional context for translation
     * @return string|null Translated text or null on failure
     */
    public function translate(
        string $text,
        string $targetLanguage,
        string $sourceLanguage = 'en',
        ?string $context = null
    ): ?string {
        if (empty($this->settings->openai_secret)) {
            Log::warning('OpenAI API key not configured');
            return null;
        }

        // If text is very long (> 4000 chars), split it
        if (strlen($text) > 4000) {
            return $this->translateLargeText($text, $targetLanguage, $sourceLanguage, $context);
        }

        return $this->performTranslation($text, $targetLanguage, $sourceLanguage, $context);
    }

    protected function translateLargeText(
        string $text,
        string $targetLanguage,
        string $sourceLanguage,
        ?string $context
    ): ?string {
        // Split by paragraphs to preserve structure
        $chunks = explode("\n\n", $text);
        $translatedChunks = [];

        foreach ($chunks as $index => $chunk) {
            if (trim($chunk) === '') {
                $translatedChunks[] = '';
                continue;
            }

            // If a single paragraph is still too huge, split by sentences (basic)
            if (strlen($chunk) > 4000) {
                $subChunks = preg_split('/(?<=[.?!])\s+/', $chunk);
                $translatedSubChunks = [];
                foreach ($subChunks as $subChunk) {
                    $translatedSubChunks[] = $this->performTranslation($subChunk, $targetLanguage, $sourceLanguage, $context);
                    // Free memory after each sub-chunk
                    unset($subChunk);
                }
                $translatedChunks[] = implode(' ', $translatedSubChunks);
                unset($subChunks, $translatedSubChunks);
            } else {
                $translatedChunks[] = $this->performTranslation($chunk, $targetLanguage, $sourceLanguage, $context);
            }

            // Free memory after each chunk
            unset($chunks[$index]);

            // Force garbage collection every 5 chunks to prevent memory leaks
            if ($index % 5 === 0) {
                gc_collect_cycles();
            }
        }

        return implode("\n\n", $translatedChunks);
    }

    protected function performTranslation(
        string $text,
        string $targetLanguage,
        string $sourceLanguage,
        ?string $context
    ): ?string {
        try {
            $systemPrompt = $this->settings->openai_context ?:
                'You are a professional translator. Translate the given text accurately while maintaining the tone and context.';

            if ($context) {
                $systemPrompt .= "\n\nAdditional context: {$context}";
            }

            $model = $this->settings->translation_model ?: self::DEFAULT_LOW_COST_MODEL;
            $payload = [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt,
                    ],
                    [
                        'role' => 'user',
                        'content' => "Translate the following text from {$sourceLanguage} to {$targetLanguage}:\n\n{$text}",
                    ],
                ],
                'temperature' => 0.3,
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->settings->openai_secret,
                'Content-Type' => 'application/json',
            ])->timeout(120)->post('https://api.openai.com/v1/chat/completions', $this->withGpt56Reasoning($payload, $model));

            if ($response->successful()) {
                $data = $response->json();
                $this->logOpenAiUsage('translation', $model, $data);
                return $data['choices'][0]['message']['content'] ?? null;
            }

            Log::error('OpenAI API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Translation failed', [
                'error' => $e->getMessage(),
                'text' => substr($text, 0, 100) . '...', // Log only start of text
                'target' => $targetLanguage,
            ]);

            return null;
        }
    }

    /**
     * Translate multiple fields at once
     *
     * @param array $fields Associative array of field_key => text
     * @param string $targetLanguage Target language code
     * @param string $sourceLanguage Source language code
     * @return array Associative array of field_key => translated_text
     */
    public function translateBatch(
        array $fields,
        string $targetLanguage,
        string $sourceLanguage = 'en'
    ): array {
        $translations = [];

        foreach ($fields as $key => $text) {
            if (empty($text)) {
                $translations[$key] = '';
                continue;
            }

            $translated = $this->translate($text, $targetLanguage, $sourceLanguage);
            $translations[$key] = $translated ?? $text;
        }

        return $translations;
    }
    /**
     * Translate a structured array of fields preserving keys
     *
     * @param array $data Associative array of content to translate
     * @param string $targetLanguage Target language code
     * @param string $sourceLanguage Source language code
     * @return array|null Translated array or null on failure
     */
    public function translateStructured(
        array $data,
        string $targetLanguage,
        string $sourceLanguage = 'en'
    ): ?array {
        if (empty($this->settings->openai_secret)) {
            Log::warning('OpenAI API key not configured');
            return null;
        }

        try {
            $systemPrompt = $this->settings->openai_context ?:
                'You are a professional translator. Translate the values in the JSON object accurately while maintaining the tone and context.';

            $systemPrompt .= "\n\nYou must return valid JSON. keys must remain unchanged. Text should be translated from {$sourceLanguage} to {$targetLanguage}. Do not translate specific brand names or technical terms that should remain in English.";

            $model = $this->settings->translation_model ?: self::DEFAULT_LOW_COST_MODEL;
            $payload = [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt,
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ],
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.3,
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->settings->openai_secret,
                'Content-Type' => 'application/json',
            ])->timeout(120)->post('https://api.openai.com/v1/chat/completions', $this->withGpt56Reasoning($payload, $model));

            if ($response->successful()) {
                $responseData = $response->json();
                $this->logOpenAiUsage('structured_translation', $model, $responseData);
                $content = $responseData['choices'][0]['message']['content'] ?? null;
                if ($content) {
                    return json_decode($content, true);
                }
            }

            Log::error('OpenAI API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Translation failed', [
                'error' => $e->getMessage(),
                'target' => $targetLanguage,
            ]);

            return null;
        }
    }
}
