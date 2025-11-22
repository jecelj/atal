<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class ApiSettings extends Settings
{
    public string $sync_api_key;

    public static function group(): string
    {
        return 'api';
    }
}
