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
        // Tax Price Configuration
        $config = FormFieldConfiguration::firstOrNew([
            'field_key' => 'tax_price',
            'entity_type' => 'used_yacht'
        ]);

        $config->options = [
            [
                'value' => 'vat_included',
                'label' => 'VAT incl.', // CHANGED to match legacy data
                'label_sl' => 'Z DDV',
                'label_si' => 'Z DDV', // Cover SI code
                'label_de' => 'MwSt. enthalten',
                'label_hr' => 'PDV uključen',
                'label_it' => 'IVA inclusa',
                'label_sk' => 's DPH',
                'label_cs' => 'včetně DPH',
                'label_pl' => 'z VAT',
                'label_es' => 'IVA incluido',
            ],
            [
                'value' => 'vat_excluded',
                'label' => 'VAT excl.', // CHANGED to match legacy data
                'label_sl' => 'Brez DDV',
                'label_si' => 'Brez DDV', // Cover SI code
                'label_de' => 'MwSt. ausweisbar', // or exklusive
                'label_hr' => 'PDV nije uključen',
                'label_it' => 'IVA esclusa',
                'label_sk' => 'bez DPH',
                'label_cs' => 'bez DPH',
                'label_pl' => 'bez VAT',
                'label_es' => 'IVA excluido',
            ],
        ];
        $config->save();

        // Fuel Configuration (Added)
        $fuelConfig = FormFieldConfiguration::firstOrNew([
            'field_key' => 'fuel',
            'entity_type' => 'used_yacht'
        ]);
        // Ensure basic props are set if creating new
        if (!$fuelConfig->exists) {
            $fuelConfig->label = 'Fuel';
            $fuelConfig->field_type = 'select';
            $fuelConfig->group = 'engine'; // Guessing group
            $fuelConfig->is_multilingual = true;
            $fuelConfig->order = 10;
        }

        $fuelConfig->options = [
            [
                'value' => 'diesel',
                'label' => 'Diesel',
                'label_sl' => 'Dizel',
                'label_si' => 'Dizel',
                'label_de' => 'Diesel',
                'label_hr' => 'Dizel',
                'label_it' => 'Diesel',
            ],
            [
                'value' => 'petrol',
                'label' => 'Petrol',
                'label_sl' => 'Bencin',
                'label_si' => 'Bencin',
                'label_de' => 'Benzin',
                'label_hr' => 'Benzin',
                'label_it' => 'Benzina',
            ],
            [
                'value' => 'electric_hybrid', // Normalized key
                'label' => 'electric-hybrid',
                'label_sl' => 'Elektro/Hibrid',
                'label_si' => 'Elektro/Hibrid',
                'label_de' => 'Elektro/Hybrid',
            ],
            [
                'value' => 'no_engine',
                'label' => 'no-engine',
                'label_sl' => 'Brez motorja',
                'label_si' => 'Brez motorja',
                'label_de' => 'Kein Motor',
            ],
        ];
        $fuelConfig->save();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // ...
    }
};
