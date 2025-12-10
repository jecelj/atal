<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Hash;
use Filament\Shield\FilamentShield;

class RolesAndUsersSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure Super Admin role exists (created by shield:install usually, but for safety)
        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        // Create Admin User
        $admin = User::firstOrCreate(
            ['email' => 'admin@atal.si'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
            ]
        );
        $admin->assignRole($superAdminRole);

        // Create Editor Role
        $editorRole = Role::firstOrCreate(['name' => 'editor', 'guard_name' => 'web']);

        // Grant Permissions to Editor
        // CONTENT (View All + Full CRUD except maybe delete if user didn't specify? "Content (vse)" usually implies full control)
        // SYNC (vse)
        // Master Data (Brand, Model, Location): Create ONLY (for inline), NO View Any (Menu hidden).

        $permissions = [
            // New Yachts
            'view_any_new::yacht',
            'view_new::yacht',
            'create_new::yacht',
            'update_new::yacht',
            'delete_new::yacht',
            'delete_any_new::yacht',
            'restore_new::yacht',
            'restore_any_new::yacht',
            'replicate_new::yacht',
            'reorder_new::yacht',

            // Used Yachts
            'view_any_used::yacht',
            'view_used::yacht',
            'create_used::yacht',
            'update_used::yacht',
            'delete_used::yacht',
            'delete_any_used::yacht',
            'restore_used::yacht',
            'restore_any_used::yacht',
            'replicate_used::yacht',
            'reorder_used::yacht',

            // News
            'view_any_news',
            'view_news',
            'create_news',
            'update_news',
            'delete_news',
            'delete_any_news',
            'restore_news',
            'restore_any_news',
            'replicate_news',
            'reorder_news',

            // Sync Resource (Permissions) - HIDDEN from Editor (Configuration)
            // 'view_any_sync::site',
            // 'view_sync::site',
            // 'create_sync::site',
            // 'update_sync::site',
            // 'delete_sync::site',

            // Sync Pages
            'page_SyncAll',
            'page_SyncNewYachts',
            'page_SyncNews',
            'page_SyncUsedYachts',

            // Dashboard Widgets
            'widget_RecentNews',
            'widget_RecentNewYachts',
            'widget_RecentUsedYachts',
            'widget_StatsOverview',
            'widget_TranslationProgressWidget',
            'widget_ImageOptimizationProgressWidget',

            // Brands (Create Only, No View Any)
            'create_brand',
            // 'view_any_brand', // Denied

            // Model Types (YachtModel) (Create Only, No View Any)
            'create_yacht::model',
            // 'view_any_yacht::model', // Denied

            // Locations (Create Only, No View Any)
            'create_location',
            // 'view_any_location', // Denied

            // Explicitly deny User / Role / Language / Config / Migration access (by not adding them)
        ];

        $editorRole->syncPermissions($permissions);

        // Create Editor User
        $editor = User::firstOrCreate(
            ['email' => 'editor@atal.si'],
            [
                'name' => 'Editor',
                'password' => Hash::make('password'),
            ]
        );
        $editor->assignRole($editorRole);
    }
}
