<?php

namespace App\Console\Commands;

use App\Models\FormFieldConfiguration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class FixVideoUrlFieldType extends Command
{
    protected $signature = 'fix:video-url-field';
    protected $description = 'Fix video_url field type to repeater';

    public function handle()
    {
        $this->info('Updating video_url field type to repeater...');

        $updated = FormFieldConfiguration::where('field_key', 'video_url')
            ->where('entity_type', 'new_yacht')
            ->update(['field_type' => 'repeater']);

        $this->info("Updated: {$updated} record(s)");

        // Verify
        $field = FormFieldConfiguration::where('field_key', 'video_url')
            ->where('entity_type', 'new_yacht')
            ->first();

        if ($field) {
            $this->info("Current field_type: {$field->field_type}");
        }

        // Clear cache
        $this->info('Clearing cache...');
        Artisan::call('cache:clear');
        Artisan::call('config:clear');

        $this->info('Done! Cache cleared.');

        return 0;
    }
}
