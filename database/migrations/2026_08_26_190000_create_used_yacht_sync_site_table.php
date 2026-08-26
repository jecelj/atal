<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('used_yacht_sync_site', function (Blueprint $table) {
            $table->id();
            $table->foreignId('used_yacht_id')->constrained('yachts')->cascadeOnDelete();
            $table->foreignId('sync_site_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['used_yacht_id', 'sync_site_id']);
        });

        // Preserve the prior behaviour: every existing used yacht initially remains
        // assigned to each currently active site. Editors can then opt out per yacht.
        $siteIds = DB::table('sync_sites')->where('is_active', true)->pluck('id');
        $timestamp = now();

        if ($siteIds->isEmpty()) {
            return;
        }

        DB::table('yachts')
            ->where('type', 'used')
            ->orderBy('id')
            ->chunkById(100, function ($yachts) use ($siteIds, $timestamp): void {
                $assignments = [];

                foreach ($yachts as $yacht) {
                    foreach ($siteIds as $siteId) {
                        $assignments[] = [
                            'used_yacht_id' => $yacht->id,
                            'sync_site_id' => $siteId,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    }
                }

                DB::table('used_yacht_sync_site')->insertOrIgnore($assignments);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('used_yacht_sync_site');
    }
};
