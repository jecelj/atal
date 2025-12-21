<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class OpenAiSettings extends Settings
{
    public ?string $openai_secret;
    public ?string $openai_context;
    public ?string $openai_model;
    public ?string $openai_prompt;
    public ?string $openai_prompt_no_images;
    public ?string $openai_translation_prompt;
    public ?string $browserless_api_key;
    public ?string $browserless_script;
    public ?string $adventure_boat_prompt;
    public ?string $yootheme_falang_prompt;
    public ?string $openai_source_language;

    public static function group(): string
    {
        return 'openai';
    }
}
