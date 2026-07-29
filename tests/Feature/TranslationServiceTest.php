<?php

namespace Tests\Feature;

use App\Services\TranslationService;
use App\Settings\OpenAiSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class TranslationServiceTest extends TestCase
{
    public function test_it_explicitly_requests_the_selected_target_language(): void
    {
        $settings = Mockery::mock(OpenAiSettings::class);
        $settings->openai_secret = 'test-key';
        $settings->openai_context = 'Translate yacht marketing copy.';
        $settings->translation_model = 'gpt-5.6-luna';

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'Deutsche Ubersetzung'],
                ]],
            ]),
        ]);

        $translated = (new TranslationService($settings))->translate('English source text', 'de', 'en');

        $this->assertSame('Deutsche Ubersetzung', $translated);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();
            $systemMessage = $payload['messages'][0]['content'] ?? '';
            $userMessage = $payload['messages'][1]['content'] ?? '';

            return str_contains($systemMessage, 'German (de)')
                && str_contains($systemMessage, 'Slovenian is allowed only when the target language code is sl.')
                && str_contains($userMessage, 'German (de) only')
                && ($payload['reasoning_effort'] ?? null) === 'none';
        });
    }

    public function test_it_explicitly_requests_the_selected_target_language_for_structured_translations(): void
    {
        $settings = Mockery::mock(OpenAiSettings::class);
        $settings->openai_secret = 'test-key';
        $settings->openai_context = 'Translate yacht marketing copy.';
        $settings->translation_model = 'gpt-5.6-luna';

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"standard:description":"Deutscher Text"}'],
                ]],
            ]),
        ]);

        $translated = (new TranslationService($settings))->translateStructured([
            'standard:description' => 'English source text',
        ], 'de', 'en');

        $this->assertSame(['standard:description' => 'Deutscher Text'], $translated);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();
            $systemMessage = $payload['messages'][0]['content'] ?? '';
            $userMessage = $payload['messages'][1]['content'] ?? '';

            return str_contains($systemMessage, 'Translate string values into German only.')
                && str_contains($userMessage, 'German (de) only');
        });
    }
}
