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
    public ?string $browserless_api_key;
    public ?string $browserless_script;

    public static function group(): string
    {
        return 'openai';
    }
}
