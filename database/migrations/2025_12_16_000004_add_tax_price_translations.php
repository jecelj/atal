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
        $config = FormFieldConfiguration::where('entity_type', 'used_yacht')
            ->where('field_key', 'tax_price')
            ->first();

        if ($config) {
            // Define options with translations
            // Keys must match 'label_code' format
            $config->options = [
                [
                    'value' => 'vat_included',
                    'label' => 'VAT included', // English default
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
                    'label' => 'VAT excluded',
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
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // ...
    }
};
