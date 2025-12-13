<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        $defaultPrompt = "You are a professional nautical translator. Translate the following technical yacht specifications JSON to the following languages: {{LANGUAGES}}.

IMPORTANT RULES:
1. Output must be valid JSON matching the structure: { 'key': { 'lang_code': 'translation' } }.
2. For 'sub_title', 'full_description', 'specifications': Provide native, professional translations.
3. FOR SLOVENIAN (sl): Use professional nautical terminology. Do NOT translate literally. (e.g. 'Head' -> 'Toaleta/WC', 'Beam' -> 'Širina', 'Draft' -> 'Ugrez').
4. Keep HTML tags unchanged.

INPUT JSON:
{{JSON}}";

        $this->migrator->add('openai.openai_translation_prompt', $defaultPrompt);
    }

    public function down(): void
    {
        $this->migrator->delete('openai.openai_translation_prompt');
    }
};
