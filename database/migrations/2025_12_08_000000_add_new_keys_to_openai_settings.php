<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        // Add openai_prompt_no_images
        $this->migrator->add('openai.openai_prompt_no_images', '');

        // Add browserless_api_key
        $this->migrator->add('openai.browserless_api_key', '');

        // Add browserless_script
        $this->migrator->add('openai.browserless_script', "export default async function({ page }) {\n  await page.goto(context.url);\n  const content = await page.content();\n  return { content };\n};");

    }

    public function down(): void
    {
        $this->migrator->delete('openai.openai_prompt_no_images');
        $this->migrator->delete('openai.browserless_api_key');
    }
};
