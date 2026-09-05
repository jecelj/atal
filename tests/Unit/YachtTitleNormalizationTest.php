<?php

namespace Tests\Unit;

use App\Models\NewYacht;
use Tests\TestCase;

class YachtTitleNormalizationTest extends TestCase
{
    public function test_english_yacht_title_is_copied_to_every_locale(): void
    {
        $yacht = new NewYacht([
            'name' => [
                'en' => 'Absolute 60 Fly',
                'sk' => '60',
            ],
        ]);

        $changed = $yacht->synchronizeNameTranslations(['en', 'sk', 'sl', 'de']);

        $this->assertTrue($changed);
        $this->assertSame([
            'en' => 'Absolute 60 Fly',
            'sk' => 'Absolute 60 Fly',
            'sl' => 'Absolute 60 Fly',
            'de' => 'Absolute 60 Fly',
        ], $yacht->getTranslations('name'));
    }

    public function test_legacy_title_without_english_value_uses_first_existing_value(): void
    {
        $yacht = new NewYacht([
            'name' => ['sk' => 'Absolute 60 Fly'],
        ]);

        $yacht->synchronizeNameTranslations(['en', 'sk', 'sl']);

        $this->assertSame('Absolute 60 Fly', $yacht->getTranslation('name', 'en', false));
        $this->assertSame('Absolute 60 Fly', $yacht->getTranslation('name', 'sk', false));
        $this->assertSame('Absolute 60 Fly', $yacht->getTranslation('name', 'sl', false));
    }

}
