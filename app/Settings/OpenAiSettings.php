<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class OpenAiSettings extends Settings
{
    public string $openai_secret;
    public string $openai_context;
    public string $openai_model;

    public static function group(): string
    {
        return 'openai';
    }
}
