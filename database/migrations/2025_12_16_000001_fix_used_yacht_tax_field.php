<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\FormFieldConfiguration;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Find the field by label "Tax price" for used_yacht
        $config = FormFieldConfiguration::where('entity_type', 'used_yacht')
            ->where(function ($q) {
                $q->where('label', 'like', '%Tax price%')
                    ->orWhere('field_key', 'tax_price')
                    ->orWhere('field_key', 'tax_status');
            })
            ->first();

        if ($config) {
            // Update the existing field
            $config->field_key = 'tax_price'; // Ensure key is distinct
            $config->field_type = 'select';
            $config->label = 'Tax price';
            $config->options = [
                ['value' => 'vat_included', 'label' => 'VAT included'],
                ['value' => 'vat_excluded', 'label' => 'VAT excluded'],
            ];
            $config->sync_as_taxonomy = false; // Ensure it sends as meta 
            $config->save();
        } else {
            // Create if it doesn't exist
            FormFieldConfiguration::create([
                'entity_type' => 'used_yacht',
                'group' => 'Basic Information', // Or appropriate group
                'field_key' => 'tax_price',
                'field_type' => 'select',
                'label' => 'Tax price',
                'is_required' => false,
                'is_multilingual' => false,
                'sync_as_taxonomy' => false,
                'order' => 10, // Adjust as needed
                'options' => [
                        ['value' => 'vat_included', 'label' => 'VAT included'],
                        ['value' => 'vat_excluded', 'label' => 'VAT excluded'],
                    ],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Optional: Revert changes?
    }
};
