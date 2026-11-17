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

    public function test_it_preserves_specification_table_rows_and_cells(): void
    {
        $service = new TranslationService(new OpenAiSettings());

        $html = '<table class="MsoTableGrid" style="width: 100%"><caption>Specifications</caption><thead><tr><th>Specification</th><th>Value</th></tr></thead><tbody><tr><td style="color: red" onclick="alert(1)">Length</td><td>6.1 m</td></tr><tr><td>Cabins</td><td>0</td></tr></tbody><tfoot><tr><td>Source</td><td>Regal</td></tr></tfoot></table>';

        $clean = $service->prepareHtmlForTranslation($html);

        $this->assertSame('<table><caption>Specifications</caption><thead><tr><th>Specification</th><th>Value</th></tr></thead><tbody><tr><td>Length</td><td>6.1 m</td></tr><tr><td>Cabins</td><td>0</td></tr></tbody><tfoot><tr><td>Source</td><td>Regal</td></tr></tfoot></table>', $clean);
        $this->assertSame($clean, $service->prepareHtmlForTranslation($clean));
    }
}
