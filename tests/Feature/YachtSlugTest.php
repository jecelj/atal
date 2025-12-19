<?php

namespace Tests\Feature;

use App\Models\NewYacht;
use App\Models\UsedYacht;
use App\Models\Brand;
use App\Models\YachtModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class YachtSlugTest extends TestCase
{
    // Use RefreshDatabase to ensure clean state
    use RefreshDatabase;

    public function test_new_and_used_yachts_share_slug_namespace()
    {
        // Setup
        $brand = Brand::create(['name' => 'Test Brand', 'slug' => 'test-brand']);

        // 1. Create a New Yacht
        $newYacht = NewYacht::create([
            'name' => ['en' => 'Test Yacht'],
            'slug' => 'test-yacht',
            'brand_id' => $brand->id,
            'state' => 'draft',
            'type' => 'new'
        ]);

        $this->assertDatabaseHas('yachts', ['slug' => 'test-yacht', 'type' => 'new']);

        // 2. Try to create Used Yacht with same slug (Simulating Import Logic)
        // Note: The import logic we modified is in the Controller/Page, not the Model itself.
        // The Model doesn't auto-increment on its own (it requires logic).
        // So here we verify that the DB enforces uniqueness (Expect Exception) OR 
        // we can test the helper logic if we extracted it, but since we didn't extract checking logic to model,
        // we will test the DB index constraint.

        $this->expectException(\Illuminate\Database\QueryException::class);

        UsedYacht::create([
            'name' => ['en' => 'Test Yacht Used'],
            // Intentionally same slug to test DB constraint
            'slug' => 'test-yacht',
            'brand_id' => $brand->id,
            'state' => 'draft',
            'type' => 'used'
        ]);
    }

    public function test_new_and_used_yachts_slug_auto_increment_logic()
    {
        // This simulates the logic we added to ReviewUsedYachtImport/ReviewOpenAIImport

        $brand = Brand::create(['name' => 'Test Brand 2', 'slug' => 'test-brand-2']);

        // 1. Create New Yacht
        NewYacht::create([
            'name' => ['en' => 'Duplicate Test'],
            'slug' => 'duplicate-test',
            'brand_id' => $brand->id,
            'type' => 'new'
        ]);

        // 2. Simulate Import Logic: Calculate Slug
        $desiredName = 'Duplicate Test';
        $slug = \Illuminate\Support\Str::slug($desiredName);
        $originalSlug = $slug;
        $count = 1;

        // Use the EXACT logic we implemented: Yacht::withoutGlobalScopes()
        while (\App\Models\Yacht::withoutGlobalScopes()->where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $count++;
        }

        // 3. Verify it generated an incremented slug
        $this->assertEquals('duplicate-test-1', $slug);

        // 4. Create Used Yacht with this new slug
        $usedYacht = UsedYacht::create([
            'name' => ['en' => 'Duplicate Test'],
            'slug' => $slug,
            'brand_id' => $brand->id,
            'type' => 'used'
        ]);

        $this->assertDatabaseHas('yachts', ['slug' => 'duplicate-test-1', 'type' => 'used']);
    }
}
