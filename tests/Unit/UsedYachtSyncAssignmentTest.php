<?php

namespace Tests\Unit;

use App\Models\SyncSite;
use App\Models\UsedYacht;
use App\Services\WordPressSyncService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class UsedYachtSyncAssignmentTest extends TestCase
{
    public function test_used_yacht_is_synced_only_to_an_assigned_active_site(): void
    {
        $site = new SyncSite(['sync_used_yachts' => true]);
        $site->id = 11;
        $yacht = new UsedYacht(['state' => 'published']);
        $yacht->setRelation('syncSites', new Collection([$site]));

        $this->assertFalse(app(WordPressSyncService::class)->isFilteredOut($yacht, $site));
    }

    public function test_used_yacht_is_filtered_when_the_site_is_not_assigned(): void
    {
        $assignedSite = new SyncSite(['sync_used_yachts' => true]);
        $assignedSite->id = 11;
        $otherSite = new SyncSite(['sync_used_yachts' => true]);
        $otherSite->id = 12;
        $yacht = new UsedYacht(['state' => 'published']);
        $yacht->setRelation('syncSites', new Collection([$assignedSite]));

        $this->assertTrue(app(WordPressSyncService::class)->isFilteredOut($yacht, $otherSite));
    }

    public function test_used_yacht_is_filtered_when_used_yacht_sync_is_disabled_for_the_site(): void
    {
        $site = new SyncSite(['sync_used_yachts' => false]);
        $site->id = 11;
        $yacht = new UsedYacht(['state' => 'published']);
        $yacht->setRelation('syncSites', new Collection([$site]));

        $this->assertTrue(app(WordPressSyncService::class)->isFilteredOut($yacht, $site));
    }
}
