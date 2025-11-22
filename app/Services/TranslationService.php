<?php

namespace App\Services;

use App\Settings\OpenAiSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranslationService
{
    protected OpenAiSettings $settings;

    public function __construct(OpenAiSettings $settings)
    {
        $this->settings = $settings;
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

        try {
            $systemPrompt = $this->settings->openai_context ?:
                'You are a professional translator. Translate the given text accurately while maintaining the tone and context.';

            if ($context) {
                $systemPrompt .= "\n\nAdditional context: {$context}";
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->settings->openai_secret,
                'Content-Type' => 'application/json',
            ])->timeout(120)->post('https://api.openai.com/v1/chat/completions', [
                        'model' => $this->settings->openai_model ?? 'gpt-4o-mini-2024-07-18',
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
                    ]);

            if ($response->successful()) {
                $data = $response->json();
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
                'text' => $text,
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
}
