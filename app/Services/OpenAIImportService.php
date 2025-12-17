<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Language;
use App\Settings\OpenAiSettings;

class OpenAIImportService
{
    /**
     * Fetch USED yacht data using specialized single-prompt approach (Adventure Boat).
     */
    public function fetchUsedYachtData(string $url, array $context = [])
    {
        set_time_limit(600);
        ini_set('max_execution_time', 600);

        // 1. FETCH SETTINGS
        $url = trim($url);
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }

        Log::info('OpenAI Used Yacht Import: Starting import for URL: ' . $url);
        $settings = app(OpenAiSettings::class);
        $apiKey = $settings->openai_secret;
        $browserlessKey = $settings->browserless_api_key;
        $browserlessScript = $settings->browserless_script;

        // Custom Prompt for Used Yacht
        $systemPrompt = $settings->adventure_boat_prompt;

        if (!$apiKey)
            return ['error' => 'OpenAI API Key not configured'];
        if (!$browserlessKey)
            return ['error' => 'Browserless API Key not configured'];
        if (empty($systemPrompt))
            return ['error' => 'Adventure Boat Prompt not configured in Settings'];

        // 2. CALL BROWSERLESS
        $browserlessStart = microtime(true);
        $scrapeResult = $this->callBrowserless($url, $browserlessKey, $browserlessScript);
        $browserlessDuration = round(microtime(true) - $browserlessStart, 2);
        Log::info("OpenAI Used Yacht Import: Browserless finished in {$browserlessDuration}s");

        if (isset($scrapeResult['error'])) {
            return ['error' => 'Browserless Error: ' . $scrapeResult['error']];
        }

        // 3. PREPARE INPUTS
        // Clean Media
        $mediaData = $scrapeResult;
        unset($mediaData['raw_html_clean']);
        unset($mediaData['url']);

        // Fix Encoding
        array_walk_recursive($mediaData, function (&$v) {
            if (is_string($v))
                $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
        });
        $jsonMedia = json_encode($mediaData, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // Clean HTML
        $rawHtml = $scrapeResult['raw_html_clean'] ?? '';
        $rawHtml = mb_convert_encoding($rawHtml, 'UTF-8', 'UTF-8');

        // Construct User Input
        $userInput = "raw_html = \"\"\"" . $rawHtml . "\"\"\"\n\n" .
            "media = " . $jsonMedia;

        Log::info('OpenAI Used Yacht Import: Calling OpenAI...');
        $openaiStart = microtime(true);

        // 4. CALL OPENAI (Single Call)
        try {
            $response = Http::withToken($apiKey)
                ->timeout(240)
                ->post('https://api.openai.com/v1/chat/completions', [ // Using chat completions for GPT-4o compatibility
                    'model' => 'gpt-4o', // Or gpt-4-turbo
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userInput]
                    ],
                    'temperature' => 0.1,
                ]);

            if ($response->failed()) {
                Log::error('OpenAI Call Failed: ' . $response->body());
                return ['error' => 'OpenAI Call Failed: ' . $response->status() . ' - ' . $response->body()];
            }

            $body = $response->json();
            $content = $body['choices'][0]['message']['content'] ?? null;

            if (!$content) {
                return ['error' => 'OpenAI returned empty content'];
            }

            // Decode
            $decoded = $this->decodeOpenAIContent($content);
            if (isset($decoded['error']))
                return $decoded;

            // 5. NORMALIZE
            // We reuse normalizeData but might need specific Used Yacht tweaks
            // For now, standard normalization is likely fine as User prompt requests specific structure
            $finalData = $this->normalizeData($decoded);

            // Add Debug info
            $finalData['_debug_prompt'] = "SYSTEM PROMPT:\n" . substr($systemPrompt, 0, 500) . "...\n\nUSER INPUT:\n" . substr($userInput, 0, 500) . "... (truncated)";
            $finalData['_debug_response'] = $content;

            return $finalData;

        } catch (\Exception $e) {
            Log::error('OpenAI Exception: ' . $e->getMessage());
            return ['error' => 'Exception: ' . $e->getMessage()];
        }
    }

    /**
     * Fetch yacht data using Browserless Scrape + OpenAI Analysis
     *
     * @param string $url
     * @param array $context Additional context (brand, model)
     * @return array
     */
    public function fetchData(string $url, array $context = [])
    {
        set_time_limit(600); // Prevent PHP timeout
        ini_set('max_execution_time', 600);

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
        $browserlessStart = microtime(true);
        $scrapeResult = $this->callBrowserless($url, $browserlessKey, $browserlessScript);
        $browserlessDuration = round(microtime(true) - $browserlessStart, 2);
        Log::info("OpenAI Import: Browserless finished in {$browserlessDuration}s");

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

        // EXTRACTION INPUT: BRAND, MODEL, URL, RAW_HTML
        $pageType = $scrapeResult['page_type'] ?? '';
        $extractionInput = "BRAND = " . $brand . "\n" .
            "MODEL = " . $model . "\n" .
            "URL = " . $url . "\n" .
            "RAW_HTML = \"\"\"" . $rawHtml . "\"\"\"";

        Log::info('OpenAI Import: Starting Parallel Requests (Media & Extraction)...');
        Log::info("OpenAI Import: Payload Sizes - Media: " . strlen($mediaInput) . " chars, Extraction: " . strlen($extractionInput) . " chars");

        $openaiStart = microtime(true);

        // 5. PARALLEL OPENAI CALLS (Step 1 & 2)
        $responses = Http::pool(function ($pool) use ($apiKey, $mediaPromptSystem, $mediaInput, $extractionPromptSystem, $extractionInput) {
            return [
                // ===== MEDIA (gpt-4.1) =====
                $pool->as('media')
                    ->withToken($apiKey)
                    ->timeout(600)
                    ->post('https://api.openai.com/v1/responses', [
                        'model' => 'gpt-4.1',
                        'input' => [
                            ['role' => 'system', 'content' => $mediaPromptSystem],
                            ['role' => 'user', 'content' => $mediaInput]
                        ],
                        'temperature' => 0.1,
                        'parallel_tool_calls' => false
                    ]),

                // ===== EXTRACTION (gpt-5.1) =====
                $pool->as('extraction')
                    ->withToken($apiKey)
                    ->timeout(240) // priporočilo: ne 600
                    ->post('https://api.openai.com/v1/responses', [
                        'model' => 'gpt-5.1',
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
                        'tool_choice' => 'auto'
                    ])
            ];
        });

        $openaiDuration = round(microtime(true) - $openaiStart, 2);
        Log::info("OpenAI Import: Parallel OpenAI finished in {$openaiDuration}s");

        // 6. PROCESS INITIAL RESPONSES
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

        // Helper to parse Custom/Standard response
        $getOpenAIContent = function ($body) {
            // Standard OpenAI Chat Completion (choices)
            if (isset($body['choices'][0]['message']['content'])) {
                return $body['choices'][0]['message']['content'];
            }

            // Custom Endpoint (v1/responses) - Iterate to find "message" type or valid text content
            // The output array might contain tool calls first. We need the final generated message.
            if (isset($body['output']) && is_array($body['output'])) {
                foreach ($body['output'] as $item) {
                    // Check for "message" type or simply content that has text
                    if (isset($item['content'][0]['text'])) {
                        // Some responses might have type 'message', others just content.
                        // We look for a non-empty text field.
                        // But we must skip 'web_search_call' if it accidentally has similar structure (unlikely but safe to check type)
                        if (isset($item['type']) && $item['type'] === 'web_search_call') {
                            continue;
                        }
                        return $item['content'][0]['text'];
                    }
                }
            }
            return null;
        };

        $mediaContent = $getOpenAIContent($mediaBody);
        $extractionContent = $getOpenAIContent($extractionBody);

        if (!$mediaContent) {
            Log::error('Media Response Empty/Invalid: ' . json_encode($mediaBody));
            return ['error' => 'Media Response Content Empty. Raw: ' . substr(json_encode($mediaBody), 0, 500)];
        }
        if (!$extractionContent) {
            Log::error('Extraction Response Empty/Invalid: ' . json_encode($extractionBody));
            return ['error' => 'Extraction Response Content Empty. Raw: ' . substr(json_encode($extractionBody), 0, 500)];
        }

        // Decode JSONs
        $decodedMedia = $this->decodeOpenAIContent($mediaContent);
        $decodedExtraction = $this->decodeOpenAIContent($extractionContent);

        if (isset($decodedMedia['error']))
            return $decodedMedia;
        if (isset($decodedExtraction['error']))
            return $decodedExtraction;

        // 7. TRANSLATION CALL (Step 3 - Sequential)
        // Only if we have extraction data
        /*
        Log::info('DEBUG: Starting Translation Call (Step 3)...');
        $transStart = microtime(true);
        $translatedData = $this->translateData($decodedExtraction, $activeLanguages, $apiKey);
        $transDuration = round(microtime(true) - $transStart, 2);
        Log::info("OpenAI Import: Translation finished in {$transDuration}s");

        // Merge translated data back into extraction data (overwriting English-only fields with Multilingual ones)
        if (!empty($translatedData)) {
            $decodedExtraction = array_replace_recursive($decodedExtraction, $translatedData);
        }
        */

        // 8. MERGE FINAL DATA
        $finalData = array_merge($decodedExtraction, $decodedMedia);

        // Append Debug Info
        // Append Debug Info
        $debugPrompt = "===== STEP 1: MEDIA INPUT =====\n" . $mediaInput . "\n\n";
        $debugPrompt .= "===== STEP 2: EXTRACTION INPUT =====\n" . $extractionInput . "\n\n";

        $debugResponse = "===== STEP 1: MEDIA RESPONSE =====\n" . mb_substr(json_encode($decodedMedia, JSON_PRETTY_PRINT), 0, 2000) . "...\n\n";
        $debugResponse .= "===== STEP 2: EXTRACTION RESPONSE (English) =====\n" . mb_substr(json_encode($decodedExtraction, JSON_PRETTY_PRINT), 0, 2000) . "...\n\n";

        /*
        if (isset($translatedData) && !empty($translatedData)) {
            // We need to fetch the translation prompt again or reconstruct it to log it, 
            // but since translateData is protected and doesn't return the prompt, 
            // we'll just log that it happened. Ideally translateData should return metadata.
            // For now, let's log the fact.
            $debugPrompt .= "===== STEP 3: TRANSLATION =====\n(Executed via translateData)\n";
            $debugResponse .= "===== STEP 3: TRANSLATION RESPONSE =====\n" . mb_substr(json_encode($translatedData, JSON_PRETTY_PRINT), 0, 2000) . "...\n";
        }
        */

        $finalData['_debug_prompt'] = $debugPrompt;
        $finalData['_debug_response'] = $debugResponse;

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
     * Translate extracted data to target languages using a dedicated API call.
     */
    protected function translateData(array $sourceData, array $languages, string $apiKey)
    {
        // Identify fields to translate and structure them as { 'en': '...' }
        $fieldsToTranslate = [
            'sub_title' => ['en' => $sourceData['sub_title']['en'] ?? ($sourceData['sub_title'] ?? '')],
            'full_description' => ['en' => $sourceData['full_description']['en'] ?? ($sourceData['full_description'] ?? '')],
            'specifications' => ['en' => $sourceData['specifications']['en'] ?? ($sourceData['specifications'] ?? '')],
            'engine_location' => ['en' => $sourceData['engine_location'] ?? ''],
        ];

        // Fetch Prompt from Settings
        $settings = app(\App\Settings\OpenAiSettings::class);
        $customPrompt = $settings->openai_translation_prompt;

        // Construct Final Prompt
        $baseInstruction = !empty($customPrompt)
            ? $customPrompt
            : "You are a professional nautical translator. Translate the following technical yacht specifications JSON to the following languages.";

        // Append the standardized footer keys
        $prompt = $baseInstruction . "\n\n" .
            "LANGUAGES:\n" . json_encode($languages) . "\n\n" .
            "INPUT JSON:\n" . json_encode($fieldsToTranslate, JSON_PRETTY_PRINT);

        try {
            // Using gpt-4.1 on Custom Endpoint (v1/responses)
            $response = Http::withToken($apiKey)
                ->timeout(120)
                ->post('https://api.openai.com/v1/responses', [
                    'model' => 'gpt-4.1',
                    'input' => [
                        [
                            'role' => 'system',
                            'content' => [['type' => 'input_text', 'text' => 'You are a helpful translator assistant. Return valid JSON only.']]
                        ],
                        [
                            'role' => 'user',
                            'content' => [['type' => 'input_text', 'text' => $prompt]]
                        ]
                    ],
                    'temperature' => 0.1,
                    'parallel_tool_calls' => false
                ]);

            if ($response->failed()) {
                Log::error('Translation Call Failed: ' . $response->body());
                return [];
            }

            // Handle Custom Endpoint Response Structure
            $body = $response->json();
            $content = null;

            if (isset($body['choices'][0]['message']['content'])) {
                $content = $body['choices'][0]['message']['content'];
            } elseif (isset($body['output'][0]['content'][0]['text'])) {
                $content = $body['output'][0]['content'][0]['text'];
            }

            if (!$content) {
                Log::error('Translation Response Empty/Invalid Format: ' . json_encode($body));
                return [];
            }

            return $this->decodeOpenAIContent($content);

        } catch (\Exception $e) {
            Log::error('Translation Error: ' . $e->getMessage());
            return [];
        }
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
                        // $decoded[$field] = array_fill_keys($activeCodes, $decoded[$field]);

                        // Modified: Only populate English, leave others empty/null
                        $decoded[$field] = ['en' => $decoded[$field]];
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
