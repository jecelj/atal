<?php

namespace Tests\Unit;

use App\Forms\Components\OrderedGalleryUpload;
use App\Models\Yacht;
use Tests\TestCase;

class OrderedGalleryUploadTest extends TestCase
{
    public function test_gallery_uploads_are_appended_and_processed_one_at_a_time(): void
    {
        $upload = OrderedGalleryUpload::make('custom_fields.galerie');

        $this->assertTrue($upload->shouldAppendFiles());
        $this->assertSame(1, $upload->getMaxParallelUploads());
        $this->assertTrue($upload->isReorderable());

        $hintActions = (new \ReflectionProperty($upload, 'hintActions'))->getValue($upload);

        $this->assertCount(1, $hintActions);
        $this->assertSame('reverseOrder', $hintActions[0]->getName());
        $this->assertSame('Reverse order', $hintActions[0]->getLabel());
    }

    public function test_media_relation_has_a_deterministic_gallery_order(): void
    {
        $sql = (new Yacht())->media()->toSql();

        $this->assertStringContainsString('order by', $sql);
        $this->assertStringContainsString('`order_column` asc', $sql);
        $this->assertStringContainsString('`id` asc', $sql);
    }
}
