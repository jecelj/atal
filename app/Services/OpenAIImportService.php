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
        // 1. MOCK MODE (For testing "Reload Mock Data" button)
        if ($url === 'http://localhost/mock-reload') {
            Log::info('OpenAI Import: Fetching MOCK data');
            $mockPath = storage_path('app/mock_openai_response.json');
            if (file_exists($mockPath)) {
                $rawMock = file_get_contents($mockPath);
                $mockDecoded = json_decode($rawMock, true);
                return $this->processApiResponse(['choices' => [['message' => ['content' => $rawMock]]]], $url);
            }
            return ['error' => 'Mock file not found'];
        }

        // 2. FETCH SETTINGS
        // Clean URL
        $url = trim($url);
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }

        Log::info('OpenAI Import: Starting import for URL: ' . $url);
        $settings = app(OpenAiSettings::class);
        $apiKey = $settings->openai_secret;
        $browserlessKey = $settings->browserless_api_key;
        $browserlessScript = $settings->browserless_script;

        if (!$apiKey) {
            return ['error' => 'OpenAI API Key not configured in Settings'];
        }
        if (!$browserlessKey) {
            return ['error' => 'Browserless API Key not configured in Settings'];
        }
        if (empty($settings->openai_prompt)) {
            return ['error' => 'Yacht Import Prompt not configured in Settings'];
        }

        // 3. CALL BROWSERLESS
        $scrapeResult = $this->callBrowserless($url, $browserlessKey, $browserlessScript);

        if (isset($scrapeResult['error'])) {
            return ['error' => 'Browserless Error: ' . $scrapeResult['error']];
        }

        // 4. PREPARE PROMPT INJECTION DATA
        $brand = $context['brand'] ?? '';
        $model = $context['model'] ?? '';

        // Languages
        $activeLanguages = Language::pluck('code')->values()->toArray();
        $jsonLanguages = json_encode($activeLanguages);

        // Media & HTML from Browserless
        $rawHtml = $scrapeResult['raw_html_clean'] ?? '';

        // Prepare Media JSON (images, pdfs, videos)
        // We exclude raw_html_clean from this media object to keep it clean
        $mediaData = $scrapeResult;
        unset($mediaData['raw_html_clean']);
        unset($mediaData['url']); // URL is already passed separately
        $jsonMedia = json_encode($mediaData, JSON_UNESCAPED_SLASHES);

        // 5. CONSTRUCT OPENAI SYSTEM PROMPT
        $systemPrompt = $settings->openai_prompt;

        // Construct Request for GPT-5.1
        // We use placeholders or append logic. 
        // User requested:
        // ### BEGIN INPUT DATA ###
        // BRAND = {{brand}}
        // MODEL = {{model}}
        // URL = {{url}}
        // LANGUAGES = {{languages_json}}
        // RAW_HTML = """{{html}}"""
        // MEDIA = {{media_json}}
        // ### END INPUT DATA ###

        $inputDataBlock = "\n\n### BEGIN INPUT DATA ###\n" .
            "BRAND = " . $brand . "\n" .
            "MODEL = " . $model . "\n" .
            "URL = " . $url . "\n" .
            "LANGUAGES = " . $jsonLanguages . "\n" .
            "RAW_HTML = \"\"\"" . $rawHtml . "\"\"\"\n" .
            "MEDIA = " . $jsonMedia . "\n" .
            "### END INPUT DATA ###";

        // Create full prompt
        $fullPromptInput = $systemPrompt . $inputDataBlock;

        // 6. CALL OPENAI (Custom Endpoint v1/responses with Server-Side Search)
        $response = Http::withToken($apiKey)
            ->timeout(180)    // 180s request timeout
            ->post('https://api.openai.com/v1/responses', [
                'model' => 'gpt-5.1',
                'input' => $fullPromptInput,

                'tools' => [
                    ['type' => 'web_search']
                ],

                'tool_choice' => 'auto',
                'parallel_tool_calls' => false,
                'max_output_tokens' => 2000,
                'response_format' => ['type' => 'json_object']
            ]);

        if ($response->failed()) {
            Log::error('OpenAI API Error: ' . $response->body());
            return ['error' => 'OpenAI API Error: ' . $response->status() . ' - ' . $response->body()];
        }

        $responseBody = $response->json();

        // 7. PROCESS RESPONSE
        $result = $this->processApiResponse($responseBody, $url);

        // Append Debug Info
        if (is_array($result)) {
            $result['_debug_prompt'] = $fullPromptInput;
            $result['_debug_response'] = json_encode($responseBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return $result;
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
