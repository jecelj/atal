<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class UsedYachtFieldsSeeder extends Seeder
{
    public function run()
    {
        // Define fields for Used Yacht
        // Keys match what is expected by Import logic and Review Page (with adjustments for consistency)
        $fields = [
            // Basic Info (Price, Year, Tax)
            [
                "entity_type" => "used_yacht",
                "group" => "Basic Information",
                "field_key" => "year",
                "field_type" => "number",
                "label" => "Year",
                "is_required" => false,
                "is_multilingual" => false,
                "order" => 1,
            ],
            [
                "entity_type" => "used_yacht",
                "group" => "Basic Information",
                "field_key" => "price",
                "field_type" => "number",
                "label" => "Price",
                "is_required" => false,
                "is_multilingual" => false,
                "order" => 2,
            ],
            [
                "entity_type" => "used_yacht",
                "group" => "Basic Information",
                "field_key" => "tax_price",
                "field_type" => "select",
                "label" => "Tax Status",
                "is_required" => false,
                "is_multilingual" => false,
                "options" => json_encode([
                    ['value' => 'vat_included', 'label' => 'VAT Included'],
                    ['value' => 'vat_excluded', 'label' => 'VAT Excluded']
                ]),
                "order" => 3,
            ],

            // Dimensions & Specs
            [
                "entity_type" => "used_yacht",
                "group" => "Dimensions & Specs",
                "field_key" => "length",
                "field_type" => "number",
                "label" => "Length (m)",
                "is_required" => false,
                "is_multilingual" => false,
                "order" => 10,
            ],
            [
                "entity_type" => "used_yacht",
                "group" => "Dimensions & Specs",
                "field_key" => "beam",
                "field_type" => "number",
                "label" => "Beam (m)",
                "is_required" => false,
                "is_multilingual" => false,
                "order" => 11,
            ],
            [
                "entity_type" => "used_yacht",
                "group" => "Dimensions & Specs",
                "field_key" => "draft",
                "field_type" => "number",
                "label" => "Draft (m)",
                "is_required" => false,
                "is_multilingual" => false,
                "order" => 12,
            ],
            [
                "entity_type" => "used_yacht",
                "group" => "Dimensions & Specs",
                "field_key" => "weight",
                "field_type" => "number",
                "label" => "Weight (kg)",
                "is_required" => false,
                "is_multilingual" => false,
                "order" => 13,
            ],
            [
                "entity_type" => "used_yacht",
                "group" => "Dimensions & Specs",
                "field_key" => "cabins",
                "field_type" => "number",
                "label" => "Cabins",
                "is_required" => false,
                "is_multilingual" => false,
                "order" => 14,
            ],
            [
                "entity_type" => "used_yacht",
                "group" => "Dimensions & Specs",
                "field_key" => "berths",
                "field_type" => "number",
                "label" => "Berths",
                "is_required" => false,
                "is_multilingual" => false,
                "order" => 15,
            ],
            [
                "entity_type" => "used_yacht",
                "group" => "Dimensions & Specs",
                "field_key" => "bathrooms",
                "field_type" => "number",
                "label" => "Bathrooms",
                "is_required" => false,
                "is_multilingual" => false,
                "order" => 16,
            ],
            [
                "entity_type" => "used_yacht",
                "group" => "Dimensions & Specs",
                "field_key" => "max_persons",
                "field_type" => "number",
                "label" => "Max Persons",
                "is_required" => false,
                "is_multilingual" => false,
                "order" => 17,
            ],
            [
                "entity_type" => "used_yacht",
                "group" => "Dimensions & Specs",
                "field_key" => "fuel_tank_capacity",
                "field_type" => "number",
                "label" => "Fuel Tank (L)",
                "is_required" => false,
                "is_multilingual" => false,
                "order" => 18,
            ],
            [
                "entity_type" => "used_yacht",
                "group" => "Dimensions & Specs",
                "field_key" => "water_capacity",
                "field_type" => "number",
                "label" => "Water Tank (L)",
                "is_required" => false,
                "is_multilingual" => false,
                "order" => 19,
            ],

            // Engine
            [
                "entity_type" => "used_yacht",
                "group" => "Engine",
                "field_key" => "engines",
                "field_type" => "text",
                "label" => "Engine Description",
                "is_required" => false,
                "is_multilingual" => false,
                "order" => 20,
            ],
            [
                "entity_type" => "used_yacht",
                "group" => "Engine",
                "field_key" => "engine_hours",
                "field_type" => "number",
                "label" => "Engine Hours",
                "is_required" => false,
                "is_multilingual" => false,
                "order" => 21,
            ],
            [
                "entity_type" => "used_yacht",
                "group" => "Engine",
                "field_key" => "fuel",
                "field_type" => "select",
                "label" => "Fuel Type",
                "is_required" => false,
                "is_multilingual" => false,
                "options" => json_encode([
                    ['value' => 'diesel', 'label' => 'Diesel'],
                    ['value' => 'petrol', 'label' => 'Petrol'],
                    ['value' => 'electric_hybrid', 'label' => 'Electric/Hybrid'],
                    ['value' => 'no_engine', 'label' => 'No Engine']
                ]),
                "order" => 22,
            ],

            // Descriptions
            [
                "entity_type" => "used_yacht",
                "group" => "Descriptions",
                "field_key" => "short_description",
                "field_type" => "textarea",
                "label" => "Short Description",
                "is_required" => false,
                "is_multilingual" => true, // Assuming descriptions can be translated
                "order" => 30,
            ],
            [
                "entity_type" => "used_yacht",
                "group" => "Descriptions",
                "field_key" => "equipment_and_other_information",
                "field_type" => "richtext",
                "label" => "Equipment & Information",
                "is_required" => false,
                "is_multilingual" => true,
                "order" => 31,
            ],

            [
                "entity_type" => "used_yacht",
                "group" => "Media",
                "field_key" => "image_1",
                "field_type" => "image",
                "label" => "Main image",
                "is_required" => false,
                "is_multilingual" => false,
                "order" => 40,
            ],
            [
                "entity_type" => "used_yacht",
                "group" => "Media",
                "field_key" => "galerie",
                "field_type" => "gallery",
                "label" => "Gallery",
                "is_required" => false,
                "is_multilingual" => false,
                "order" => 41,
            ],
            [
                "entity_type" => "used_yacht",
                "group" => "Media",
                "field_key" => "pdf_b",
                "field_type" => "file",
                "label" => "PDF Brochure",
                "is_required" => false,
                "is_multilingual" => false,
                "order" => 42,
            ],
            [
                "entity_type" => "used_yacht",
                "group" => "Media",
                "field_key" => "video_url",
                "field_type" => "text",
                "label" => "Video URL (Youtube)",
                "is_required" => false,
                "is_multilingual" => false,
                "order" => 43,
            ],
        ];

        // Clean existing Used Yacht fields
        DB::table('form_field_configurations')->where('entity_type', 'used_yacht')->delete();

        // Prepare data with defaults
        $data = [];
        $now = Carbon::now();
        foreach ($fields as $field) {
            $data[] = array_merge([
                "validation_rules" => "[]",
                "sync_as_taxonomy" => false,
                "created_at" => $now,
                "updated_at" => $now,
                "options" => null // default
            ], $field);
        }

        DB::table('form_field_configurations')->insert($data);
    }
}
