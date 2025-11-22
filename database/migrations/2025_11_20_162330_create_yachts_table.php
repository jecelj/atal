<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Check if table exists to handle potential re-runs or existing state
        if (Schema::hasTable('yachts')) {
            Schema::table('yachts', function (Blueprint $table) {
                // If we need to modify existing table
            });
            return;
        }

        Schema::create('yachts', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['new', 'used'])->default('new');
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('yacht_model_id')->nullable()->constrained('yacht_models')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->integer('year')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yachts');
    }
};
