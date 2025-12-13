<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Language;
use App\Settings\OpenAiSettings;

class OpenAIImportService
{
    /**
     * Fetch yacht data using Browserless Scrape + OpenAI Analysis
     *
     * @param string $url
     * @param array $context Additional context (brand, model)
     * @return array
     */
    public function fetchData(string $url, array $context = [])
    {
        // Prevent PHP timeout
        set_time_limit(600);

        // 1. MOCK MODE
        if ($url === 'http://localhost/mock-reload') {
            Log::info('OpenAI Import: Fetching MOCK data');
            $mockPath = storage_path('app/mock_openai_response.json');
            if (file_exists($mockPath)) {
                $rawMock = file_get_contents($mockPath);
                return $this->processApiResponse(json_decode($rawMock, true), $url);
            }
            return ['error' => 'Mock file not found'];
        }

        // 2. FETCH SETTINGS
        $url = trim($url);
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }

        Log::info('OpenAI Import: Starting import for URL: ' . $url);
        $settings = app(OpenAiSettings::class);
        $apiKey = $settings->openai_secret;
        $browserlessKey = $settings->browserless_api_key;
        $browserlessScript = $settings->browserless_script;

        // Prompts
        $mediaPromptSystem = $settings->openai_prompt; // "OpenAI Media Prompt"
        $extractionPromptSystem = $settings->openai_prompt_no_images; // "OpenAI Yacht Data Extractor"

        if (!$apiKey)
            return ['error' => 'OpenAI API Key not configured'];
        if (!$browserlessKey)
            return ['error' => 'Browserless API Key not configured'];
        if (empty($mediaPromptSystem))
            return ['error' => 'OpenAI Media Prompt not configured'];
        if (empty($extractionPromptSystem))
            return ['error' => 'OpenAI Yacht Data Extractor Prompt not configured'];

        // 3. CALL BROWSERLESS
        $scrapeResult = $this->callBrowserless($url, $browserlessKey, $browserlessScript);
        if (isset($scrapeResult['error'])) {
            return ['error' => 'Browserless Error: ' . $scrapeResult['error']];
        }

        // 4. PREPARE INPUTS
        $brand = $context['brand'] ?? '';
        $model = $context['model'] ?? '';

        // Sanitize strings
        $brand = mb_convert_encoding($brand, 'UTF-8', 'UTF-8');
        $model = mb_convert_encoding($model, 'UTF-8', 'UTF-8');

        // Languages
        $activeLanguages = Language::pluck('code')->values()->toArray();
        $jsonLanguages = json_encode($activeLanguages);

        // Media Data (exclude raw html)
        $mediaData = $scrapeResult;
        unset($mediaData['raw_html_clean']);
        unset($mediaData['url']);

        // Fix Encoding for JSON
        array_walk_recursive($mediaData, function (&$v) {
            if (is_string($v)) {
                $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
            }
        });

        $jsonMedia = json_encode($mediaData, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // HTML
        $rawHtml = $scrapeResult['raw_html_clean'] ?? '';
        $rawHtml = mb_convert_encoding($rawHtml, 'UTF-8', 'UTF-8'); // Sanitize HTML for Extraction Call

        // Prepare Prompts inputs
        // MEDIA INPUT: BRAND, MODEL, MEDIA
        $mediaInput = "BRAND = " . $brand . "\n" .
            "MODEL = " . $model . "\n" .
            "MEDIA = " . $jsonMedia;

        // EXTRACTION INPUT: BRAND, MODEL, LANGUAGES, RAW_HTML
        $extractionInput = "BRAND = " . $brand . "\n" .
            "MODEL = " . $model . "\n" .
            "LANGUAGES = " . $jsonLanguages . "\n" .
            "RAW_HTML = \"\"\"" . $rawHtml . "\"\"\"";

        Log::info('OpenAI Import: Starting Parallel Requests (Media & Extraction)...');

        // 5. PARALLEL OPENAI CALLS
        // Note: User requested models 'gpt-4.1' and 'o4'. 
        // We map them to standard models or use as requested if available via custom endpoint.
        // Assuming standard API usage: v1/chat/completions.

        $responses = Http::pool(function ($pool) use ($apiKey, $mediaPromptSystem, $mediaInput, $extractionPromptSystem, $extractionInput) {
            return [

                // ===== MEDIA (no tools) =====
                $pool->as('media')
                    ->withToken($apiKey)
                    ->timeout(600)
                    ->post('https://api.openai.com/v1/responses', [
                        'model' => 'gpt-4.1',
                        'input' => [
                            [
                                'role' => 'system',
                                'content' => $mediaPromptSystem
                            ],
                            [
                                'role' => 'user',
                                'content' => $mediaInput
                            ]
                        ],
                        'temperature' => 0.1,
                        'parallel_tool_calls' => false
                    ]),

                // ===== EXTRACTION (with web_search) =====
                $pool->as('extraction')
                    ->withToken($apiKey)
                    ->timeout(600)
                    ->post('https://api.openai.com/v1/responses', [
                        'model' => 'gpt-5.2-pro',
                        'input' => [
                            [
                                'role' => 'system',
                                'content' => [
                                    ['type' => 'input_text', 'text' => $extractionPromptSystem]
                                ]
                            ],
                            [
                                'role' => 'user',
                                'content' => [
                                    ['type' => 'input_text', 'text' => $extractionInput]
                                ]
                            ]
                        ],
                        'tools' => [
                            ['type' => 'web_search']
                        ],
                        'tool_choice' => 'auto',
                        // 'temperature' => 0.1,          // NI DOVOLJENO ZA TA MODEL
                        'parallel_tool_calls' => false
                    ])
            ];
        });

        // 6. PROCESS RESPONSES
        if ($responses['media']->failed()) {
            Log::error('OpenAI Media Call Failed: ' . $responses['media']->body());
            return ['error' => 'Media Call Failed: ' . $responses['media']->status() . ' - ' . $responses['media']->body()];
        }
        if ($responses['extraction']->failed()) {
            Log::error('OpenAI Extraction Call Failed: ' . $responses['extraction']->body());
            return ['error' => 'Extraction Call Failed: ' . $responses['extraction']->status() . ' - ' . $responses['extraction']->body()];
        }

        $mediaBody = $responses['media']->json();
        $extractionBody = $responses['extraction']->json();

        // Check if responses are valid
        $mediaContent = $mediaBody['choices'][0]['message']['content'] ?? null;
        $extractionContent = $extractionBody['choices'][0]['message']['content'] ?? null;

        if (!$mediaContent || !$extractionContent) {
            return ['error' => 'One of the OpenAI responses was empty. Check logs.'];
        }

        // Decode JSONs
        $decodedMedia = $this->decodeOpenAIContent($mediaContent);
        $decodedExtraction = $this->decodeOpenAIContent($extractionContent);

        if (isset($decodedMedia['error']))
            return $decodedMedia; // Return decoding error
        if (isset($decodedExtraction['error']))
            return $decodedExtraction;

        // 7. MERGE DATA
        // Merge extraction data (main) with media data
        $finalData = array_merge($decodedExtraction, $decodedMedia);

        // Append Debug Info
        $finalData['_debug_prompt'] = "MEDIA PROMPT:\n" . substr($mediaPromptSystem, 0, 200) . "...\n\nEXTRACTION PROMPT:\n" . substr($extractionPromptSystem, 0, 200) . "...";
        $finalData['_debug_response'] = "Merged from two calls.";

        // Normalization (using existing processed method logic, but adapted)
        // Since we already decoded, we just need to pass it through normalization if needed.
        // Actually, processApiResponse handled normalization. I should extract normalization logic or reuse it.
        // I will refactor processApiResponse to take array and normalize it.

        return $this->normalizeData($finalData);
    }

    protected function decodeOpenAIContent($content)
    {
        // Clean Markdown
        if (preg_match('/^```(?:json)?\s*(.*)\s*```$/s', trim($content), $matches)) {
            $content = $matches[1];
        }
        $decoded = json_decode($content, true);
        if ($decoded === null) {
            return ['error' => 'JSON Decode Error: ' . json_last_error_msg()];
        }
        return $decoded;
    }

    protected function normalizeData($decoded)
    {
        // ... (Logic from old processApiResponse) ...
        // I will implement this in a separate helper to avoid code duplication if I kept the old method, 
        // but since I replaced fetchData, I can just put logic here or calling a helper.

        // Normalization Logic reuse:
        if (isset($decoded['engine_location'])) {
            if (is_string($decoded['engine_location']) && strtolower($decoded['engine_location']) === 'outboard') {
                $decoded['engine_location'] = 'external';
            }
            if (!is_array($decoded['engine_location'])) {
                $decoded['engine_location'] = [$decoded['engine_location']];
            }
        }
        if (array_key_exists('number_of_bathrooms', $decoded) && $decoded['number_of_bathrooms'] === null) {
            $decoded['number_of_bathrooms'] = '0';
        }
        if (array_key_exists('no_cabins', $decoded) && $decoded['no_cabins'] === null) {
            $decoded['no_cabins'] = '0';
        }
        // Fix mock typo for gallery
        if (isset($decoded['gallery_interrior']) && !isset($decoded['gallery_interior'])) {
            $decoded['gallery_interior'] = $decoded['gallery_interrior'];
            unset($decoded['gallery_interrior']);
        }

        // Normalize Videos
        if (isset($decoded['video_url'])) {
            $videos = [];
            $rawVideos = is_array($decoded['video_url']) ? $decoded['video_url'] : [$decoded['video_url']];
            foreach ($rawVideos as $v) {
                if (is_string($v) && !empty($v)) {
                    $videos[] = ['url' => $v];
                } elseif (is_array($v) && isset($v['url'])) {
                    $videos[] = $v;
                }
            }
            $decoded['video_url'] = $videos;
        }

        // Normalize Length
        if (isset($decoded['length'])) {
            $val = str_replace(',', '.', $decoded['length']);
            $decoded['length'] = (float) filter_var($val, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        }

        // Normalize Multilingual Fields
        $multilingualFields = ['sub_title', 'full_description', 'specifications'];
        $activeCodes = \App\Models\Language::pluck('code')->toArray();

        foreach ($multilingualFields as $field) {
            if (isset($decoded[$field])) {
                if (is_string($decoded[$field])) {
                    $decoded[$field] = array_fill_keys($activeCodes, $decoded[$field]);
                }
            }
        }

        return $decoded;
    }


    /**
     * Remove performWebSearch which is no longer used in linear flow
     */

    /**
     * Call Browserless Function Endpoint
     */
    protected function callBrowserless(string $url, string $token, string $script)
    {
        Log::info('OpenAI Import: Calling Browserless...');

        // Use default script if empty (fallback)
        if (empty($script)) {
            Log::warning('OpenAI Import: Browserless Script missing in settings, using Default (ESM).');
            $script = "export default async function({ page, context }) { await page.goto(context.url); const content = await page.content(); return { raw_html_clean: content }; };";
        }

        Log::info('OpenAI Import: Sending Script to Browserless', ['length' => strlen($script), 'preview' => substr($script, 0, 50)]);

        // We assume script is just the JS code content.
        // Browserless expects JSON: { "code": "...", "context": { "url": "..." } }

        // Sanitize Script for Browserless /function endpoint (ESM)
        if (str_contains($script, 'module.exports')) {
            $script = str_replace(['module.exports =', 'module.exports='], 'export default', $script);
            Log::info('OpenAI Import: Converted module.exports to export default for compatibility.');
        }

        // Sanitize 'networkidle' (deprecated) -> 'networkidle0'
        if (str_contains($script, 'networkidle') && !str_contains($script, 'networkidle0') && !str_contains($script, 'networkidle2')) {
            $script = str_replace(['"networkidle"', "'networkidle'"], "'networkidle0'", $script);
            Log::info('OpenAI Import: Converted networkidle to networkidle0 for Puppeteer compatibility.');
        }

        // Replace placeholders (e.g. {{url}}) if present
        if (str_contains($script, '{{url}}')) {
            $script = str_replace('{{url}}', $url, $script);
            Log::info('OpenAI Import: Replaced {{url}} placeholder with actual URL.');
        }

        Log::info('OpenAI Import: Final Script to Browserless', ['preview' => substr($script, 0, 100)]);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            // Cache control might not be needed for /function but harmless
        ])
            ->timeout(300)
            ->post("https://production-sfo.browserless.io/function?token={$token}", [
                'code' => $script,
                'context' => [
                    'url' => $url
                ]
            ]);

        if ($response->failed()) {
            Log::error('Browserless Error: ' . $response->body());
            return ['error' => 'Status ' . $response->status() . ' - ' . $response->body()];
        }

        $data = $response->json();

        // Log keys returned
        Log::info('Browserless Response Keys: ', array_keys($data));

        return $data;
    }

    /**
     * Process the standard OpenAI Response
     */
    protected function processApiResponse(array $response, string $url = '')
    {
        $data = null;

        // Standard OpenAI format check
        if (isset($response['choices'][0]['message']['content'])) {
            $data = $response['choices'][0]['message']['content'];
        } elseif (isset($response['output'])) {
            // Mock format fallback
            foreach ($response['output'] as $index => $output) {
                if (($output['type'] ?? '') === 'message' && isset($output['content'])) {
                    foreach ($output['content'] as $contentPart) {
                        if (isset($contentPart['text'])) {
                            $data = $contentPart['text'];
                            break 2;
                        }
                    }
                }
            }
        }

        if ($data) {
            // Clean Markdown
            if (preg_match('/^```(?:json)?\s*(.*)\s*```$/s', trim($data), $matches)) {
                $data = $matches[1];
            }

            $decoded = json_decode($data, true);

            if ($decoded === null) {
                return ['error' => 'Decoded JSON is null. JSON Error: ' . json_last_error_msg()];
            }

            // Normalization
            if (isset($decoded['engine_location'])) {
                // Convert 'outboard' to 'external'
                if (is_string($decoded['engine_location']) && strtolower($decoded['engine_location']) === 'outboard') {
                    $decoded['engine_location'] = 'external';
                }
                // Ensure array for CheckboxList
                if (!is_array($decoded['engine_location'])) {
                    $decoded['engine_location'] = [$decoded['engine_location']];
                }
            }
            if (array_key_exists('number_of_bathrooms', $decoded) && $decoded['number_of_bathrooms'] === null) {
                $decoded['number_of_bathrooms'] = '0';
            }
            if (array_key_exists('no_cabins', $decoded) && $decoded['no_cabins'] === null) {
                $decoded['no_cabins'] = '0';
            }
            // Fix mock typo for gallery
            if (isset($decoded['gallery_interrior']) && !isset($decoded['gallery_interior'])) {
                $decoded['gallery_interior'] = $decoded['gallery_interrior'];
                unset($decoded['gallery_interrior']);
            }

            // Normalize Videos for Repeater: ['url1', 'url2'] -> [['url' => 'url1'], ['url' => 'url2']]
            if (isset($decoded['video_url'])) {
                $videos = [];
                $rawVideos = is_array($decoded['video_url']) ? $decoded['video_url'] : [$decoded['video_url']];
                foreach ($rawVideos as $v) {
                    if (is_string($v) && !empty($v)) {
                        $videos[] = ['url' => $v];
                    } elseif (is_array($v) && isset($v['url'])) {
                        $videos[] = $v;
                    }
                }
                $decoded['video_url'] = $videos;
            }

            // Normalize Length (ensure numeric)
            if (isset($decoded['length'])) {
                // Handle comma decimals (10,4 -> 10.4)
                $val = str_replace(',', '.', $decoded['length']);
                // Extract number
                $decoded['length'] = (float) filter_var($val, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            }

            // Normalize Multilingual Fields (ensure array)
            // If OpenAI returns a string for these, we broadcast it to all active languages
            // to ensure the Tabs in the form are populated.
            $multilingualFields = ['sub_title', 'full_description', 'specifications'];
            $activeCodes = \App\Models\Language::pluck('code')->toArray();

            foreach ($multilingualFields as $field) {
                if (isset($decoded[$field])) {
                    if (is_string($decoded[$field])) {
                        // Broadcast string to all languages
                        $decoded[$field] = array_fill_keys($activeCodes, $decoded[$field]);
                    } elseif (is_array($decoded[$field])) {
                        // Ensure keys exist? Not strictly necessary if Filament handles missing keys gracefully.
                        // But good to ensure it's not [0 => 'desc'] but ['en' => 'desc']
                        // OpenAI prompt asks for keys.
                    }
                }
            }

            return $decoded;
        }

        return ['error' => 'No text data found in OpenAI response'];
    }
}
