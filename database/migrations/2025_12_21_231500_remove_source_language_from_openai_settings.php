<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        $this->migrator->delete('openai.openai_source_language');
    }

    public function down(): void
    {
        $this->migrator->add('openai.openai_source_language', 'en');
    }
};
