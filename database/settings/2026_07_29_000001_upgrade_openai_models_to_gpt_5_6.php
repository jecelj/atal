<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        $this->setOpenAiModel('translation_model', 'gpt-5.6-luna');
        $this->setOpenAiModel('ai_import_media_model', 'gpt-5.6-luna');
        $this->setOpenAiModel('ai_import_extraction_model', 'gpt-5.6-terra');
        $this->setOpenAiModel('ai_import_retry_model', 'gpt-5.6-terra');
    }

    public function down(): void
    {
        $lowCostModel = 'gpt-4o-mini-2024-07-18';

        $this->setOpenAiModel('translation_model', $lowCostModel);
        $this->setOpenAiModel('ai_import_media_model', $lowCostModel);
        $this->setOpenAiModel('ai_import_extraction_model', $lowCostModel);
        $this->setOpenAiModel('ai_import_retry_model', $lowCostModel);
    }

    private function setOpenAiModel(string $name, string $model): void
    {
        DB::table('settings')->upsert(
            [[
                'group' => 'openai',
                'name' => $name,
                'payload' => json_encode($model),
                'locked' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['group', 'name'],
            ['payload', 'updated_at']
        );
    }
};
