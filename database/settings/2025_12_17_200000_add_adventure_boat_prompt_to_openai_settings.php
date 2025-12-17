<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration {
    public function up(): void
    {
        $this->migrator->add('openai.adventure_boat_prompt', 'You are a helpful assistant for classifying Adventure Boat yachts.');
    }
};
