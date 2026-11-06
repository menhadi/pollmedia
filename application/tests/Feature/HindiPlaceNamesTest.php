<?php

namespace Tests\Feature;

use App\Services\HindiPlaceNames;
use App\Services\PublicLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class HindiPlaceNamesTest extends TestCase
{
    use RefreshDatabase;

    private string $namesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->namesPath = tempnam(sys_get_temp_dir(), 'hindi-names-');
        $database = new PDO('sqlite:'.$this->namesPath);
        $database->exec('CREATE TABLE display_names(name TEXT PRIMARY KEY,hi TEXT,basis TEXT)');
        $statement = $database->prepare('INSERT INTO display_names VALUES(?,?,?)');
        foreach ([['daman & diu', 'दमन और दीव', 'source_devanagari'], ['male', 'माले', 'source_devanagari'], ['budheda', 'बुधेड़ा', 'neural_transliteration'], ['sikandarpur', 'सिकंदरपुर', 'source_devanagari'], ['kozhikode', 'कोजिकोड', 'neural_transliteration']] as $row) {
            $statement->execute($row);
        }
        $database = null;
        config(['hindi-place-names.path' => $this->namesPath, 'public_translations' => ['village' => 'गाँव', 'district' => 'जिला', 'Explore' => 'देखें']]);
        app()->setLocale('hi');
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(HindiPlaceNames::class);
        gc_collect_cycles();
        @unlink($this->namesPath);
        parent::tearDown();
    }

    public function test_bulk_names_display_in_dropdowns_headings_map_labels_and_tables_without_changing_codes(): void
    {
        $html = '<html><body><h1>Budheda village</h1><select><option value="154775">Budheda</option></select><div class="map-tooltip">Sikandarpur · Kozhikode</div><table><tr><td><a href="/village/154775">Budheda</a></td><td>154775</td></tr></table><script>{"name":"Budheda","code":"154775"}</script></body></html>';
        $result = PublicLanguage::html($html);
        $this->assertStringContainsString('<h1>बुधेड़ा गाँव</h1>', $result);
        $this->assertStringContainsString('value="154775">बुधेड़ा</option>', $result);
        $this->assertStringContainsString('सिकंदरपुर &middot; कोजिकोड', $result);
        $this->assertStringContainsString('href="/village/154775">बुधेड़ा</a>', $result);
        $this->assertStringContainsString('<td>154775</td>', $result);
        $this->assertSame('PC: बुधेड़ा', PublicLanguage::translate('PC: Budheda'));
        $this->assertSame('https://example.org/Budheda', PublicLanguage::translate('https://example.org/Budheda'));
        $this->assertSame('Budheda@example.org', PublicLanguage::translate('Budheda@example.org'));
        $this->assertStringContainsString('{"name":"Budheda","code":"154775"}', $result);
    }

    public function test_translating_option_labels_keeps_implicit_values_and_hidden_metric_metadata(): void
    {
        $result = PublicLanguage::html('<select><option>Budheda</option></select><select id="colour-metric"><option value="village">Budheda</option></select>');
        $this->assertStringContainsString('value="Budheda">बुधेड़ा</option>', $result);
        $this->assertStringContainsString('value="village">Budheda</option>', $result);
    }

    public function test_edited_hindi_spelling_overrides_generated_names_and_english_stays_original(): void
    {
        $this->assertSame('दमन और दीव', PublicLanguage::placeText('Daman and Diu'));
        $this->assertSame('बुधेड़ा', PublicLanguage::translate('Budheda'));
        config(['public_translations.Budheda' => 'बुधेड़ा']);
        $this->assertSame('बुधेड़ा', PublicLanguage::translate('Budheda'));
        app()->setLocale('en');
        $this->assertSame('<h1>Budheda</h1>', PublicLanguage::html('<h1>Budheda</h1>'));
    }

    public function test_place_names_do_not_use_the_meaning_of_english_interface_words(): void
    {
        config(['public_translations.Male' => 'पुरुष']);
        $result = PublicLanguage::html('<h1>Male village</h1><select id="village-filter"><option value="v-3">Male</option></select><p>Male: 123</p>');
        $this->assertStringContainsString('<h1>माले गाँव</h1>', $result);
        $this->assertStringContainsString('value="v-3">माले</option>', $result);
        $this->assertStringContainsString('<p>पुरुष: 123</p>', $result);
    }

    public function test_name_endpoint_handles_dynamic_options_in_batches_and_rejects_invalid_input(): void
    {
        $this->getJson('/api/language/hi/names?'.http_build_query(['names' => json_encode(['Budheda', 'Sikandarpur', 'No such indexed name'])]))
            ->assertOk()->assertJsonPath('names.Budheda', 'बुधेड़ा')->assertJsonPath('names.Sikandarpur', 'सिकंदरपुर')->assertJsonPath('names.No such indexed name', 'No such indexed name');
        $this->getJson('/api/language/hi/names?names=not-json')->assertUnprocessable();
        $this->getJson('/api/language/hi/names?'.http_build_query(['names' => json_encode(array_fill(0, 81, 'Budheda'))]))->assertUnprocessable();
    }

    public function test_lookup_does_not_create_a_missing_store_or_modify_it(): void
    {
        $before = hash_file('sha256', $this->namesPath);
        $names = app(HindiPlaceNames::class);
        $this->assertSame('बुधेड़ा', $names->translated('BUDHEDA'));
        $this->assertSame($before, hash_file('sha256', $this->namesPath));
        config(['hindi-place-names.path' => $this->namesPath.'.missing']);
        $this->assertNull((new HindiPlaceNames)->translated('Budheda'));
        $this->assertFileDoesNotExist($this->namesPath.'.missing');
    }
}
