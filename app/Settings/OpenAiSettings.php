<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class OpenAiSettings extends Settings
{
    public ?string $openai_secret;
    public ?string $openai_context;
    public ?string $openai_model;
    public ?string $translation_model;
    public ?string $ai_import_media_model;
    public ?string $ai_import_extraction_model;
    public ?string $ai_import_retry_model;
    public ?string $openai_prompt;
    public ?string $openai_prompt_no_images;
    public ?string $openai_translation_prompt;
    public ?string $browserless_api_key;
    public ?string $browserless_script;
    public ?bool $scrapingbee_enabled;
    public ?string $scrapingbee_api_key;
    public ?string $scrapingbee_strategy;
    public ?string $scrapingbee_domains;
    public ?bool $scrapingbee_render_js;
    public ?bool $scrapingbee_premium_proxy;
    public ?int $scrapingbee_wait;
    public ?string $scrapingbee_wait_browser;
    public ?string $scrapingbee_js_scenario;
    public ?string $adventure_boat_prompt;
    public ?string $yootheme_falang_prompt;

    public static function group(): string
    {
        return 'openai';
    }
}
