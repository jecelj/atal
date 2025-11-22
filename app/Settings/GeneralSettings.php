<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public string $api_key;

    public static function group(): string
    {
        return 'general';
    }
}
