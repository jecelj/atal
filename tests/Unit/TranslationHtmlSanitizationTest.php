<?php

namespace Tests\Unit;

use App\Services\TranslationService;
use App\Settings\OpenAiSettings;
use Tests\TestCase;

class TranslationHtmlSanitizationTest extends TestCase
{
    public function test_it_removes_clipboard_html_noise_but_preserves_meaningful_markup(): void
    {
        $service = new TranslationService(new OpenAiSettings());

        $html = '<div class="MsoNormal">&bull;&nbsp;First item&nbsp;&nbsp;&nbsp;</div><script>alert(1)</script><div><strong class="x">Important</strong></div>';

        $this->assertSame('<p>• First item </p><p><strong>Important</strong></p>', $service->prepareHtmlForTranslation($html));
    }
}
