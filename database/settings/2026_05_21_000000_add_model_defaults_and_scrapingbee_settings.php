<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        $this->migrator->add('openai.translation_model', 'gpt-5.4');
        $this->migrator->add('openai.ai_import_media_model', 'gpt-5.4');
        $this->migrator->add('openai.ai_import_extraction_model', 'gpt-5.4');
        $this->migrator->add('openai.ai_import_retry_model', 'gpt-5.5');

        $this->migrator->add('openai.scrapingbee_enabled', false);
        $this->migrator->add('openai.scrapingbee_api_key', '');
        $this->migrator->add('openai.scrapingbee_strategy', 'fallback');
        $this->migrator->add('openai.scrapingbee_domains', '');
        $this->migrator->add('openai.scrapingbee_premium_proxy', false);
        $this->migrator->add('openai.scrapingbee_wait', 3000);
        $this->migrator->add('openai.scrapingbee_wait_browser', 'networkidle2');
        $this->migrator->add('openai.scrapingbee_js_scenario', '');
    }

    public function down(): void
    {
        $this->migrator->delete('openai.translation_model');
        $this->migrator->delete('openai.ai_import_media_model');
        $this->migrator->delete('openai.ai_import_extraction_model');
        $this->migrator->delete('openai.ai_import_retry_model');

        $this->migrator->delete('openai.scrapingbee_enabled');
        $this->migrator->delete('openai.scrapingbee_api_key');
        $this->migrator->delete('openai.scrapingbee_strategy');
        $this->migrator->delete('openai.scrapingbee_domains');
        $this->migrator->delete('openai.scrapingbee_premium_proxy');
        $this->migrator->delete('openai.scrapingbee_wait');
        $this->migrator->delete('openai.scrapingbee_wait_browser');
        $this->migrator->delete('openai.scrapingbee_js_scenario');
    }
};
