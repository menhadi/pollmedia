<?php

namespace Tests\Feature;

use App\Services\AgriExampleReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgriExampleGalleryTest extends TestCase
{
    use RefreshDatabase;

    public function test_gallery_has_three_bilingual_previews_and_six_downloads(): void
    {
        Http::preventStrayRequests();
        $page = $this->get('/agri-insights')->assertOk()->assertSee('Jatipur example reports')->assertSee('131596')->assertSee('English HTML')->assertSee('हिन्दी HTML')->assertDontSee('id="farmer-subdistrict-field" hidden', false);
        $this->assertSame(3, substr_count($page->getContent(), 'class="example-report-card"'));
        $this->assertGreaterThan(strpos($page->getContent(), 'id="farmer-review"'), strpos($page->getContent(), 'id="example-reports"'));
        foreach (['crop-condition', 'field-planning', 'crop-options'] as $report) {
            foreach (['en', 'hi'] as $language) {
                $page->assertSee(route('farmers.examples.show', compact('report', 'language')), false)
                    ->assertSee(route('farmers.examples.download', compact('report', 'language')), false);
            }
        }
    }

    public function test_all_six_previews_have_the_correct_language_values_and_advisory_labels(): void
    {
        Http::preventStrayRequests();
        foreach (app(AgriExampleReports::class)->catalogue() as $report => $example) {
            foreach (['en', 'hi'] as $language) {
                $this->get('/agri-insights/examples/'.$report.'/'.$language)->assertOk()
                    ->assertSee('<html lang="'.$language.'">', false)
                    ->assertSee($example['documents'][$language]['title'])
                    ->assertSee($example['documents'][$language]['government']['coverage'])
                    ->assertSee($language === 'hi' ? 'केवल पूर्व-प्रकाशित संदर्भ' : 'Dated reference only')
                    ->assertSee('2026-10-27T08:30:00+05:30')->assertSee('131596')->assertSee('0.4698')->assertSee('0.2381');
            }
        }
    }

    public function test_downloads_are_allowlisted_attachments_with_verified_content(): void
    {
        Storage::fake('local');
        $reports = app(AgriExampleReports::class)->catalogue();
        $pdf = "%PDF-1.4\nApproved example fixture\n%%EOF";
        foreach ($reports as &$example) {
            foreach (['en', 'hi'] as $language) {
                $asset = &$example['pdfs'][$language];
                Storage::disk('local')->put('agri-examples/'.$asset['file'], $pdf);
                $asset['sha256'] = hash('sha256', $pdf);
            }
        }
        unset($example, $asset);
        $this->partialMock(AgriExampleReports::class)->shouldReceive('catalogue')->andReturn($reports);
        foreach ($reports as $report => $example) {
            foreach (['en', 'hi'] as $language) {
                $this->get('/agri-insights/examples/'.$report.'/'.$language.'/download')->assertOk()
                    ->assertDownload($example['pdfs'][$language]['file'])->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
            }
        }
        Storage::disk('local')->put('agri-examples/'.$reports['crop-condition']['pdfs']['en']['file'], 'altered');
        $this->get('/agri-insights/examples/crop-condition/en/download')->assertNotFound();
    }

    public function test_unknown_report_and_language_are_not_downloadable(): void
    {
        $this->get('/agri-insights/examples/private-report/en')->assertNotFound();
        $this->get('/agri-insights/examples/crop-condition/fr/download')->assertNotFound();
    }
}
