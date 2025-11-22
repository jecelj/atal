<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('form_field_configurations', function (Blueprint $table) {
            $table->id();
            $table->enum('entity_type', ['new_yacht', 'used_yacht']);
            $table->string('field_key');
            $table->enum('field_type', ['text', 'textarea', 'select', 'image', 'gallery', 'brand', 'model', 'number', 'date', 'richtext']);
            $table->string('label');
            $table->boolean('is_required')->default(false);
            $table->boolean('is_multilingual')->default(false);
            $table->integer('order')->default(0);
            $table->json('options')->nullable();
            $table->json('validation_rules')->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'field_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('form_field_configurations');
    }
};
