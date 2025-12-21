<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        $defaultPrompt = "You are a professional translator. Translate the following text from {SOURCE_LANG} to {TARGET_LANG}. Maintain formatting and HTML tags.";

        $this->migrator->add('openai.yootheme_falang_prompt', $defaultPrompt);
    }

    public function down(): void
    {
        $this->migrator->delete('openai.yootheme_falang_prompt');
    }
};
