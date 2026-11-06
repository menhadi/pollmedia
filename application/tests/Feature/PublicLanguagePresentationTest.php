<?php

namespace Tests\Feature;

use App\Services\PublicLanguage;
use Tests\TestCase;

class PublicLanguagePresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('hi');
        config(['public_translations' => ['Pilibhit' => 'पीलीभीत', 'district' => 'जिला', 'Literacy (%)' => 'साक्षरता (%)']]);
    }

    public function test_names_and_measurements_translate_without_changing_identifiers_or_source_values(): void
    {
        $html = '<html lang="hi"><head><title>Pilibhit district</title></head><body><h1>Pilibhit district</h1><a href="/india/districts/pilibhit" data-id="Pilibhit">Literacy (%)</a><input value="Pilibhit" placeholder="Pilibhit"><script type="application/json">{"name":"Pilibhit"}</script><textarea>Pilibhit</textarea><code>Pilibhit</code></body></html>';
        $result = PublicLanguage::html($html);
        $this->assertStringContainsString('<h1>पीलीभीत जिला</h1>', $result);
        $this->assertStringContainsString('href="/india/districts/pilibhit" data-id="Pilibhit"', $result);
        $this->assertStringContainsString('value="Pilibhit" placeholder="पीलीभीत"', $result);
        $this->assertStringContainsString('{"name":"Pilibhit"}', $result);
        $this->assertStringContainsString('<textarea>Pilibhit</textarea>', $result);
        $this->assertStringContainsString('<code>Pilibhit</code>', $result);
    }

    public function test_english_is_unchanged_and_partial_words_are_not_replaced(): void
    {
        $this->assertSame('Pilibhitshire · पीलीभीत जिला', PublicLanguage::translate('Pilibhitshire · Pilibhit district'));
        app()->setLocale('en');
        $html = '<h1>Pilibhit district</h1>';
        $this->assertSame($html, PublicLanguage::html($html));
        $this->assertSame('Pilibhit district', PublicLanguage::text('Pilibhit district'));
    }

    public function test_saved_translations_are_text_not_executable_markup(): void
    {
        config(['public_translations' => ['Pilibhit' => '<script>alert(1)</script>']]);
        $result = PublicLanguage::html('<h1>Pilibhit</h1>');
        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringNotContainsString('<script>', $result);
    }

    public function test_unlisted_names_and_explicit_source_labels_remain_honest(): void
    {
        $this->assertSame('Unlisted village', PublicLanguage::translate('Unlisted village'));
        $result = PublicLanguage::html('<p translate="no">Pilibhit district</p>');
        $this->assertStringContainsString('>Pilibhit district</p>', $result);
    }

    public function test_complete_descriptions_translate_despite_template_whitespace(): void
    {
        config(['public_translations' => config('hindi')]);
        $result = PublicLanguage::html("<h2>Where people lived &amp; who could read</h2><p>\n  Browse values, inspect sources\n and suggest corrections.\n</p><button>Hide data table</button>");
        $this->assertStringContainsString('लोग कहाँ रहते थे और कितने साक्षर थे', $result);
        $this->assertStringContainsString('आँकड़े देखें, स्रोत जाँचें और सुधार सुझाएँ।', $result);
        $this->assertStringContainsString('आँकड़ों की तालिका छिपाएँ', $result);
        $this->assertStringNotContainsString('Browse values', $result);
    }

    public function test_dynamic_counts_and_chart_descriptions_keep_numbers_and_source_codes(): void
    {
        config(['public_translations' => config('hindi')]);
        $result = PublicLanguage::html('<h2>Compare 57 subdistricts</h2><p>24.1 percentage points separate male and female literacy. The bars use the same 0–100% scale.</p><select><option value="P_LIT">Literate population · total</option></select>');
        $this->assertStringContainsString('तुलना करें 57 तहसीलें', $result);
        $this->assertStringContainsString('24.1 प्रतिशत अंकों का अंतर', $result);
        $this->assertStringContainsString('value="P_LIT">साक्षर जनसंख्या &middot; कुल', $result);
        $this->assertSame('district-pca-2011-6361 · आधिकारिक स्रोत', PublicLanguage::translate('district-pca-2011-6361 · official source'));
    }

    public function test_translation_cache_refreshes_when_administrator_changes_wording(): void
    {
        config(['public_translations' => ['Description' => 'विवरण']]);
        $this->assertSame('विवरण', PublicLanguage::translate('Description'));
        config(['public_translations' => ['Description' => 'परिचय']]);
        $this->assertSame('परिचय', PublicLanguage::translate('Description'));
        config(['public_translations' => []]);
        $this->assertSame('Description', PublicLanguage::translate('Description'));
    }
}
