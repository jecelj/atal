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
        $importModel = $settings->ai_import_extraction_model ?: 'gpt-5.4';

        // Custom Prompt for Used Yacht
        $systemPrompt = $settings->adventure_boat_prompt;

        if (!$apiKey)
            return ['error' => 'OpenAI API Key not configured'];
        if (empty($systemPrompt))
            return ['error' => 'Adventure Boat Prompt not configured in Settings'];

        // 2. SCRAPE PAGE
        $scrapeResult = $this->scrapePage($url, $settings, 'OpenAI Used Yacht Import');

        if (isset($scrapeResult['error'])) {
            return ['error' => $scrapeResult['error']];
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
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $importModel,
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
        $mediaModel = $settings->ai_import_media_model ?: 'gpt-5.4';
        $extractionModel = $settings->ai_import_extraction_model ?: 'gpt-5.4';

        // Prompts
        $mediaPromptSystem = $settings->openai_prompt; // "OpenAI Media Prompt"
        $extractionPromptSystem = $settings->openai_prompt_no_images; // "OpenAI Yacht Data Extractor"

        if (!$apiKey)
            return ['error' => 'OpenAI API Key not configured'];
        if (empty($mediaPromptSystem))
            return ['error' => 'OpenAI Media Prompt not configured'];
        if (empty($extractionPromptSystem))
            return ['error' => 'OpenAI Yacht Data Extractor Prompt not configured'];

        // 3. SCRAPE PAGE
        $scrapeResult = $this->scrapePage($url, $settings, 'OpenAI Import');

        if (isset($scrapeResult['error'])) {
            return ['error' => $scrapeResult['error']];
        }

        // 4. PREPARE INPUTS
        $brand = $context['brand'] ?? '';
        $model = $this->normalizeContextModel($context['model'] ?? '', $url);

        // Sanitize strings
        $brand = mb_convert_encoding($brand, 'UTF-8', 'UTF-8');
        $model = mb_convert_encoding($model, 'UTF-8', 'UTF-8');

        // Languages
        $activeLanguages = Language::pluck('code')->values()->toArray();
        $jsonLanguages = json_encode($activeLanguages);

        // Media Data (exclude raw html)
        $mediaData = $this->prepareMediaDataForOpenAI($scrapeResult, $url, $brand, $model);

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
        $responses = Http::pool(function ($pool) use ($apiKey, $mediaPromptSystem, $mediaInput, $extractionPromptSystem, $extractionInput, $mediaModel, $extractionModel) {
            return [
                // ===== MEDIA =====
                $pool->as('media')
                    ->withToken($apiKey)
                    ->timeout(600)
                    ->post('https://api.openai.com/v1/responses', [
                        'model' => $mediaModel,
                        'input' => [
                            ['role' => 'system', 'content' => $mediaPromptSystem],
                            ['role' => 'user', 'content' => $mediaInput]
                        ],
                        'temperature' => 0.1,
                        'parallel_tool_calls' => false
                    ]),

                // ===== EXTRACTION =====
                $pool->as('extraction')
                    ->withToken($apiKey)
                    ->timeout(240) // priporočilo: ne 600
                    ->post('https://api.openai.com/v1/responses', [
                        'model' => $extractionModel,
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

        $decodedMedia = $this->applyMediaFallbacks(
            $decodedMedia,
            $mediaData['images'] ?? [],
            $mediaData['pdfs'] ?? [],
            $mediaData['videos'] ?? []
        );

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

        if (isset($decoded['video_url'])) {
            $decoded['video_url'] = $this->normalizeVideoUrlList($decoded['video_url']);
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

    protected function prepareMediaDataForOpenAI(array $scrapeResult, string $url, string $brand, string $model): array
    {
        $mediaData = $scrapeResult;
        unset($mediaData['raw_html_clean']);
        unset($mediaData['url']);

        $originalImages = $mediaData['images'] ?? [];
        $rankedImages = $this->rankImageUrls($originalImages, $url, $brand, $model);
        $relevantImages = array_values(array_filter($rankedImages, fn($item) => $item['score'] > 0));

        $mediaData['images_original_count'] = count($originalImages);
        $mediaData['images_relevant_count'] = count($relevantImages);

        if (!empty($relevantImages)) {
            $mediaData['images'] = array_slice(array_column($relevantImages, 'url'), 0, 80);
        } else {
            $mediaData['images'] = array_slice(array_column($rankedImages, 'url'), 0, 80);
        }

        return $mediaData;
    }

    protected function normalizeContextModel(string $model, string $url): string
    {
        $model = trim($model);

        if ($model !== '' && !filter_var($model, FILTER_VALIDATE_URL)) {
            return $model;
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $lastSegment = end($segments) ?: '';
        $lastSegment = preg_replace('/\.(html?|php)$/i', '', $lastSegment);

        return trim(ucwords(str_replace('-', ' ', $lastSegment))) ?: $model;
    }

    protected function rankImageUrls(array $urls, string $pageUrl, string $brand, string $model): array
    {
        $tokens = $this->buildMediaRelevanceTokens($pageUrl, $brand, $model);
        $currentModelTokens = $tokens['current_model_tokens'];

        $ranked = [];

        foreach (array_values(array_unique(array_filter($urls))) as $index => $url) {
            $lowerUrl = strtolower(rawurldecode($url));
            $score = 0;

            foreach ($tokens['strong'] as $token) {
                if ($token !== '' && str_contains($lowerUrl, $token)) {
                    $score += 100;
                }
            }

            foreach ($tokens['medium'] as $token) {
                if ($token !== '' && str_contains($lowerUrl, $token)) {
                    $score += 25;
                }
            }

            foreach ($tokens['weak'] as $token) {
                if ($token !== '' && str_contains($lowerUrl, $token)) {
                    $score += 5;
                }
            }

            if ($this->looksLikeOtherModelImage($lowerUrl, $currentModelTokens)) {
                $score -= 100;
            }

            $ranked[] = [
                'url' => $url,
                'score' => $score,
                'index' => $index,
            ];
        }

        usort($ranked, fn($a, $b) => ($b['score'] <=> $a['score']) ?: ($a['index'] <=> $b['index']));

        return $ranked;
    }

    protected function buildMediaRelevanceTokens(string $pageUrl, string $brand, string $model): array
    {
        $modelSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $model), '-'));
        $modelCompact = strtolower(preg_replace('/[^a-z0-9]+/i', '', $model));

        $path = strtolower(parse_url($pageUrl, PHP_URL_PATH) ?? '');
        $pathSegments = array_values(array_filter(explode('/', trim($path, '/'))));
        $lastPathSegment = end($pathSegments) ?: '';

        $strong = array_values(array_unique(array_filter([
            $modelSlug,
            $modelCompact,
            str_replace('-', '', $lastPathSegment),
            $lastPathSegment,
        ])));

        $medium = [];
        foreach (array_merge(explode('-', $modelSlug), explode('-', $lastPathSegment)) as $token) {
            $token = trim($token);
            if (strlen($token) >= 3 || preg_match('/^\d{2,}$/', $token)) {
                $medium[] = $token;
            }
        }

        return [
            'strong' => array_values(array_unique($strong)),
            'medium' => array_values(array_unique($medium)),
            'weak' => [],
            'current_model_tokens' => array_values(array_unique(array_filter([$modelSlug, $modelCompact]))),
        ];
    }

    protected function looksLikeOtherModelImage(string $url, array $currentModelTokens): bool
    {
        foreach ($currentModelTokens as $token) {
            if ($token !== '' && str_contains($url, $token)) {
                return false;
            }
        }

        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? $url);
        $filename = pathinfo($path, PATHINFO_FILENAME);

        return preg_match('/(?:^|[-_])(?:ls|lx)\d{1,3}(?:[-_]|$)/i', $filename) === 1
            || preg_match('/(?:^|[-_])\d{2,4}(?:sav|surf|obx|bx)(?:[-_]|$)/i', $filename) === 1;
    }

    protected function applyMediaFallbacks(array $decodedMedia, array $images, array $pdfs, array $videos): array
    {
        if (!empty($images)) {
            if (empty($decodedMedia['cover_image'])) {
                $decodedMedia['cover_image'] = $images[0];
            }

            if (empty($decodedMedia['grid_image'])) {
                $decodedMedia['grid_image'] = $images[0];
            }

            if (empty($decodedMedia['grid_image_hover']) && isset($images[1])) {
                $decodedMedia['grid_image_hover'] = $images[1];
            }

            foreach (['gallery_exterior', 'gallery_interior', 'gallery_cockpit', 'gallery_layout'] as $galleryKey) {
                if (!isset($decodedMedia[$galleryKey]) || !is_array($decodedMedia[$galleryKey])) {
                    $decodedMedia[$galleryKey] = [];
                }
            }

            if (empty($decodedMedia['gallery_exterior'])) {
                $decodedMedia['gallery_exterior'] = $images;
            }
        }

        if (empty($decodedMedia['pdf_brochure']) && !empty($pdfs)) {
            $decodedMedia['pdf_brochure'] = $pdfs[0];
        }

        $videos = $this->normalizeVideoUrlList($videos);

        if (empty($decodedMedia['video_url']) && !empty($videos)) {
            $decodedMedia['video_url'] = $videos;
        }

        return $decodedMedia;
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
        $translationModel = $settings->translation_model ?: 'gpt-5.4';

        // Construct Final Prompt
        $baseInstruction = !empty($customPrompt)
            ? $customPrompt
            : "You are a professional nautical translator. Translate the following technical yacht specifications JSON to the following languages.";

        // Append the standardized footer keys
        $prompt = $baseInstruction . "\n\n" .
            "LANGUAGES:\n" . json_encode($languages) . "\n\n" .
            "INPUT JSON:\n" . json_encode($fieldsToTranslate, JSON_PRETTY_PRINT);

        try {
            $response = Http::withToken($apiKey)
                ->timeout(120)
                ->post('https://api.openai.com/v1/responses', [
                    'model' => $translationModel,
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

    protected function scrapePage(string $url, OpenAiSettings $settings, string $logPrefix): array
    {
        $scrapingBeeMode = $this->getScrapingBeeMode($settings, $url);

        if ($scrapingBeeMode === 'primary') {
            $start = microtime(true);
            $result = $this->callScrapingBee($url, $settings);
            $duration = round(microtime(true) - $start, 2);
            Log::info("{$logPrefix}: ScrapingBee finished in {$duration}s", [
                'images' => count($result['images'] ?? []),
                'html_length' => strlen($result['raw_html_clean'] ?? ''),
            ]);

            return isset($result['error'])
                ? ['error' => 'ScrapingBee Error: ' . $result['error']]
                : $result;
        }

        if (!$settings->browserless_api_key) {
            return ['error' => 'Browserless API Key not configured'];
        }

        $start = microtime(true);
        $browserlessResult = $this->callBrowserless($url, $settings->browserless_api_key, $settings->browserless_script);
        $duration = round(microtime(true) - $start, 2);
        Log::info("{$logPrefix}: Browserless finished in {$duration}s", [
            'images' => count($browserlessResult['images'] ?? []),
            'html_length' => strlen($browserlessResult['raw_html_clean'] ?? ''),
        ]);

        if (isset($browserlessResult['error'])) {
            return ['error' => 'Browserless Error: ' . $browserlessResult['error']];
        }

        if ($scrapingBeeMode === 'fallback' && $this->needsScrapingBeeFallback($browserlessResult)) {
            $start = microtime(true);
            $scrapingBeeResult = $this->callScrapingBee($url, $settings);
            $duration = round(microtime(true) - $start, 2);
            Log::info("{$logPrefix}: ScrapingBee fallback finished in {$duration}s", [
                'images' => count($scrapingBeeResult['images'] ?? []),
                'html_length' => strlen($scrapingBeeResult['raw_html_clean'] ?? ''),
            ]);

            if (!isset($scrapingBeeResult['error'])) {
                return $this->mergeScrapeResults($browserlessResult, $scrapingBeeResult);
            }

            Log::warning("{$logPrefix}: ScrapingBee fallback failed", ['error' => $scrapingBeeResult['error']]);
        }

        return $browserlessResult;
    }

    protected function getScrapingBeeMode(OpenAiSettings $settings, string $url): string
    {
        if (!$settings->scrapingbee_enabled) {
            return 'disabled';
        }

        $strategy = $settings->scrapingbee_strategy ?: 'fallback';

        if ($strategy === 'always') {
            return 'primary';
        }

        if ($strategy === 'domain_only') {
            return $this->matchesScrapingBeeDomain($url, $settings->scrapingbee_domains ?? '')
                ? 'primary'
                : 'disabled';
        }

        return 'fallback';
    }

    protected function matchesScrapingBeeDomain(string $url, string $domains): bool
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        $host = preg_replace('/^www\./', '', $host);

        if ($host === '') {
            return false;
        }

        foreach (explode(',', $domains) as $domain) {
            $domain = strtolower(trim($domain));
            $domain = preg_replace('/^www\./', '', $domain);

            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                return true;
            }
        }

        return false;
    }

    protected function needsScrapingBeeFallback(array $result): bool
    {
        $html = trim($result['raw_html_clean'] ?? '');
        $imageCount = count($result['images'] ?? []);

        return $html === '' || $imageCount < 3;
    }

    protected function mergeScrapeResults(array $primary, array $secondary): array
    {
        $merged = array_merge($primary, $secondary);

        foreach (['images', 'pdfs', 'videos'] as $key) {
            $merged[$key] = array_values(array_unique(array_merge(
                $primary[$key] ?? [],
                $secondary[$key] ?? []
            )));
        }

        if (empty($primary['raw_html_clean']) && !empty($secondary['raw_html_clean'])) {
            $merged['raw_html_clean'] = $secondary['raw_html_clean'];
        }

        $merged['scraper_sources'] = array_values(array_unique(array_filter([
            $primary['scraper_source'] ?? null,
            $secondary['scraper_source'] ?? null,
        ])));

        return $merged;
    }

    protected function callScrapingBee(string $url, OpenAiSettings $settings): array
    {
        if (!$settings->scrapingbee_api_key) {
            return ['error' => 'ScrapingBee API Key not configured'];
        }

        $params = [
            'api_key' => $settings->scrapingbee_api_key,
            'url' => $url,
            'render_js' => $settings->scrapingbee_render_js === false ? 'false' : 'true',
            'premium_proxy' => $settings->scrapingbee_premium_proxy ? 'true' : 'false',
        ];

        if (!empty($settings->scrapingbee_wait)) {
            $params['wait'] = (int) $settings->scrapingbee_wait;
        }

        if (!empty($settings->scrapingbee_wait_browser)) {
            $params['wait_browser'] = $settings->scrapingbee_wait_browser;
        }

        if (!empty(trim($settings->scrapingbee_js_scenario ?? ''))) {
            $params['js_scenario'] = $settings->scrapingbee_js_scenario;
        }

        $response = Http::timeout(300)->get('https://app.scrapingbee.com/api/v1', $params);

        if ($response->failed()) {
            Log::error('ScrapingBee Error: ' . $response->body());
            return ['error' => 'Status ' . $response->status() . ' - ' . $response->body()];
        }

        $html = $response->body();

        if (trim($html) === '') {
            return ['error' => 'Empty HTML response'];
        }

        return $this->buildScrapeResultFromHtml($url, $html, 'scrapingbee');
    }

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

        if (!is_array($data)) {
            Log::error('Browserless Error: Invalid JSON response', ['body' => substr($response->body(), 0, 500)]);
            return ['error' => 'Invalid JSON response from Browserless'];
        }

        $data = $this->normalizeBrowserlessResult($data, $url);

        // Log keys returned
        Log::info('Browserless Response Keys: ', array_keys($data));

        return $data;
    }

    protected function normalizeBrowserlessResult(array $data, string $url): array
    {
        $html = $data['raw_html_clean']
            ?? $data['raw_html']
            ?? $data['html']
            ?? $data['content']
            ?? data_get($data, 'data.raw_html_clean')
            ?? data_get($data, 'data.raw_html')
            ?? data_get($data, 'data.html')
            ?? data_get($data, 'data.content')
            ?? null;

        if (is_string($html) && trim($html) !== '') {
            $data['raw_html_clean'] = $html;

            foreach (['raw_html', 'html', 'content'] as $duplicateKey) {
                if (($data[$duplicateKey] ?? null) === $html) {
                    unset($data[$duplicateKey]);
                }
            }
        }

        $data['url'] ??= $url;

        return $data;
    }

    protected function buildScrapeResultFromHtml(string $url, string $html, string $source): array
    {
        return [
            'scraper_source' => $source,
            'page_type' => 'scraped_page',
            'url' => $url,
            'raw_html_clean' => $html,
            'images' => $this->extractMediaUrls($html, $url, 'image'),
            'pdfs' => $this->extractMediaUrls($html, $url, 'pdf'),
            'videos' => $this->extractMediaUrls($html, $url, 'video'),
        ];
    }

    protected function extractMediaUrls(string $html, string $baseUrl, string $type): array
    {
        $urls = [];

        $addUrl = function ($value) use (&$urls, $baseUrl, $type) {
            if (!is_string($value) || trim($value) === '') {
                return;
            }

            foreach ($this->splitPossibleUrlList($value) as $candidate) {
                $absoluteUrl = $this->absoluteMediaUrl($candidate, $baseUrl);

                if ($absoluteUrl && $this->isWantedMediaUrl($absoluteUrl, $type)) {
                    if ($type === 'video') {
                        $absoluteUrl = $this->canonicalVideoUrl($absoluteUrl);

                        if (!$absoluteUrl) {
                            continue;
                        }
                    }

                    $urls[] = $absoluteUrl;
                }
            }
        };

        libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html);

        if ($loaded) {
            foreach (['img', 'source', 'a', 'video', 'iframe', 'meta', 'link'] as $tagName) {
                foreach ($dom->getElementsByTagName($tagName) as $node) {
                    foreach ([
                        'src',
                        'href',
                        'content',
                        'srcset',
                        'data-src',
                        'data-srcset',
                        'data-lazy-src',
                        'data-lazy-srcset',
                        'data-original',
                        'data-full',
                        'data-image',
                        'data-bg',
                    ] as $attribute) {
                        if ($node->hasAttribute($attribute)) {
                            $addUrl($node->getAttribute($attribute));
                        }
                    }
                }
            }

            foreach ($dom->getElementsByTagName('*') as $node) {
                if ($node->hasAttribute('style')) {
                    $this->extractCssUrls($node->getAttribute('style'), $addUrl);
                }
            }
        }

        libxml_clear_errors();

        $this->extractCssUrls($html, $addUrl);

        preg_match_all('~https?:\\/\\/[^"\'\s<>\\\\]+~i', $html, $matches);
        foreach ($matches[0] ?? [] as $match) {
            $addUrl($match);
        }

        return array_values(array_unique($urls));
    }

    protected function splitPossibleUrlList(string $value): array
    {
        $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($value === '') {
            return [];
        }

        $parts = [];

        foreach (explode(',', $value) as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '') {
                continue;
            }

            $parts[] = preg_split('/\s+/', $chunk)[0] ?? $chunk;
        }

        return $parts;
    }

    protected function extractCssUrls(string $value, callable $addUrl): void
    {
        preg_match_all('/url\((["\']?)(.*?)\1\)/i', $value, $matches);

        foreach ($matches[2] ?? [] as $url) {
            $addUrl($url);
        }
    }

    protected function absoluteMediaUrl(string $candidate, string $baseUrl): ?string
    {
        $candidate = trim($candidate, " \t\n\r\0\x0B\"'");

        if ($candidate === '' || str_starts_with($candidate, 'data:') || str_starts_with($candidate, 'blob:')) {
            return null;
        }

        if (str_starts_with($candidate, '//')) {
            return 'https:' . $candidate;
        }

        if (preg_match('/^https?:\/\//i', $candidate)) {
            return $candidate;
        }

        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? null;

        if (!$host) {
            return null;
        }

        if (str_starts_with($candidate, '/')) {
            return "{$scheme}://{$host}{$candidate}";
        }

        $path = $parts['path'] ?? '/';
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return "{$scheme}://{$host}{$directory}/{$candidate}";
    }

    protected function isWantedMediaUrl(string $url, string $type): bool
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        $urlLower = strtolower($url);

        if ($type === 'image' && $this->isLikelyDecorativeImage($urlLower)) {
            return false;
        }

        return match ($type) {
            'image' => preg_match('/\.(jpe?g|png|webp|avif|gif)(?:$|\?)/i', $url) === 1
                || str_contains($path, '/cdn-cgi/image/'),
            'pdf' => preg_match('/\.pdf(?:$|\?)/i', $url) === 1,
            'video' => $this->canonicalVideoUrl($url) !== null,
            default => false,
        };
    }

    public function normalizeVideoUrlList($rawVideos): array
    {
        $videos = [];
        $seen = [];
        $rawVideos = is_array($rawVideos) ? $rawVideos : [$rawVideos];

        foreach ($rawVideos as $video) {
            $item = is_array($video) ? $video : ['url' => $video];
            $canonicalUrl = $this->canonicalVideoUrl((string) ($item['url'] ?? ''));

            if (!$canonicalUrl) {
                continue;
            }

            $key = strtolower($canonicalUrl);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $item['url'] = $canonicalUrl;
            $videos[] = $item;
        }

        return $videos;
    }

    protected function canonicalVideoUrl(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\"'");

        if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, 'blob:')) {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';

        if (!$host) {
            return null;
        }

        if (preg_match('/\.(mp4|mov|m4v|webm)$/i', $path)) {
            $scheme = $parts['scheme'] ?? 'https';
            $normalized = "{$scheme}://{$host}{$path}";

            return $query ? "{$normalized}?{$query}" : $normalized;
        }

        if ($host === 'youtu.be' && preg_match('~^/([^/?#]{11})~', $path, $matches)) {
            return 'https://www.youtube.com/watch?v=' . $matches[1];
        }

        if ($host === 'youtube.com' || $host === 'www.youtube.com' || $host === 'm.youtube.com') {
            parse_str($query, $queryParams);

            if (!empty($queryParams['v']) && preg_match('/^[A-Za-z0-9_-]{11}$/', $queryParams['v'])) {
                return 'https://www.youtube.com/watch?v=' . $queryParams['v'];
            }

            if (preg_match('~/(?:embed|shorts)/([A-Za-z0-9_-]{11})~', $path, $matches)) {
                return 'https://www.youtube.com/watch?v=' . $matches[1];
            }
        }

        if ($host === 'player.vimeo.com') {
            if (preg_match('~^/video/(\d+)~', $path, $matches)) {
                return 'https://vimeo.com/' . $matches[1];
            }

            return null;
        }

        if ($host === 'vimeo.com' || $host === 'www.vimeo.com') {
            if (preg_match('~/(?:.*?/)?(\d+)(?:$|/)~', $path, $matches)) {
                return 'https://vimeo.com/' . $matches[1];
            }
        }

        return null;
    }

    protected function isLikelyDecorativeImage(string $url): bool
    {
        foreach (['favicon', 'logo', 'icon', 'sprite', 'placeholder', 'browser-bar', 'apple-touch-icon'] as $needle) {
            if (str_contains($url, $needle)) {
                return true;
            }
        }

        return false;
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

            if (isset($decoded['video_url'])) {
                $decoded['video_url'] = $this->normalizeVideoUrlList($decoded['video_url']);
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
