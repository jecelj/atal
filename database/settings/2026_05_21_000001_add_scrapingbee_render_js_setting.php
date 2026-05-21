<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        $this->migrator->add('openai.scrapingbee_render_js', true);
    }

    public function down(): void
    {
        $this->migrator->delete('openai.scrapingbee_render_js');
    }
};
