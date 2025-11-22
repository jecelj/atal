<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        // Get API key from .env or generate new one
        $apiKey = env('SYNC_API_KEY') ?: bin2hex(random_bytes(32));

        $this->migrator->add('api.sync_api_key', $apiKey);
    }

    public function down(): void
    {
        $this->migrator->delete('api.sync_api_key');
    }
};
