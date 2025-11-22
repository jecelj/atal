<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        // Create new OpenAI settings group with default values
        $this->migrator->add('openai.openai_secret', '');
        $this->migrator->add('openai.openai_context', 'You are a professional translator. Translate the given text accurately while maintaining the tone and context.');
        $this->migrator->add('openai.openai_model', 'gpt-4o-mini-2024-07-18');
    }

    public function down(): void
    {
        $this->migrator->delete('openai.openai_secret');
        $this->migrator->delete('openai.openai_context');
        $this->migrator->delete('openai.openai_model');
    }
};
