<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        $lowCostModel = 'gpt-4o-mini-2024-07-18';

        $this->migrator->update('openai.translation_model', fn() => $lowCostModel);
        $this->migrator->update('openai.ai_import_media_model', fn() => $lowCostModel);
        $this->migrator->update('openai.ai_import_extraction_model', fn() => $lowCostModel);
        $this->migrator->update('openai.ai_import_retry_model', fn() => $lowCostModel);
    }

    public function down(): void
    {
        $this->migrator->update('openai.translation_model', fn() => 'gpt-5.4');
        $this->migrator->update('openai.ai_import_media_model', fn() => 'gpt-5.4');
        $this->migrator->update('openai.ai_import_extraction_model', fn() => 'gpt-5.4');
        $this->migrator->update('openai.ai_import_retry_model', fn() => 'gpt-5.5');
    }
};
