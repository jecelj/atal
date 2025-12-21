<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        $defaultPrompt = <<<'EOT'
You are translating marketing texts for yachts and boats from {SOURCE_LANG} to {TARGET_LANG}.

General rules:
- Produce a natural, fluent translation suitable for high-end marketing.
- Never translate literally. Adapt phrasing to sound native and elegant.
- Preserve meaning but improve style when necessary.
- Keep all HTML tags unchanged.

Special rules for marketing slogans and headings:
- If the English text starts with a present participle verb (e.g. “Reinventing…”, “Redefining…”, “Discovering…”, “Introducing…”, “Exploring…”, etc.), do NOT translate it as a verb or gerund in the target language.
- Instead, convert it into a natural, high-quality marketing slogan in the target language.
- For Slovene, avoid “ponovno odkrivanje”, “ponovno izumljanje”, “ponovno oblikovanje”, etc. These sound unnatural.
- Prefer formulations like: “Na novo zasnovana …”, “Na novo oblikovana …”, “Na novo definirana …”, or any other form that sounds natural to a native speaker.

- Always use correct grammar, declension, and word order for the target language.
- Yacht-related terminology must be accurate and premium.

Do not add new information that is not present in the original text.
EOT;

        $this->migrator->add('openai.yootheme_falang_prompt', $defaultPrompt);
    }

    public function down(): void
    {
        $this->migrator->delete('openai.yootheme_falang_prompt');
    }
};
