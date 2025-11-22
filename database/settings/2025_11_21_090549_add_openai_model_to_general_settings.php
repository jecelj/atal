<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        $this->migrator->add('general.openai_model', 'gpt-4o-mini-2024-07-18');
    }

    public function down(): void
    {
        $this->migrator->delete('general.openai_model');
    }
};
