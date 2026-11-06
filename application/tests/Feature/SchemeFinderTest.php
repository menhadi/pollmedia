<?php

namespace Tests\Feature;

use App\Services\AadhaarAutofill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SchemeFinderTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheme_checker_is_bilingual_optional_and_does_not_submit_profiles(): void
    {
        $this->get('/my-place/schemes?lang=en')->assertOk()->assertSee('Aadhaar is optional')->assertSee('not identity verification')->assertSee('This local catalogue is limited')->assertDontSee('name="name"', false)->assertDontSee('name="dob"', false);
        $this->get('/my-place/schemes?lang=hi')->assertOk()->assertSee('मेरे लिए योजनाएँ खोजें')->assertSee('आधार देना वैकल्पिक है')->assertSee('पहचान सत्यापन नहीं');
    }

    public function test_autofill_requires_consent_and_returns_only_editable_fields_without_storing_files(): void
    {
        Storage::fake('local');
        $this->postJson('/my-place/schemes/autofill', ['photo' => UploadedFile::fake()->image('card.png')])->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->postJson('/my-place/schemes/autofill', ['consent' => '1', 'photo' => UploadedFile::fake()->create('card.pdf', 10, 'application/pdf')])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->mock(AadhaarAutofill::class)->shouldReceive('read')->once()->andReturn(['name' => 'Example Citizen', 'dob' => '1985-01-01', 'state' => '9', 'district' => '173']);
        $this->postJson('/my-place/schemes/autofill', ['consent' => '1', 'photo' => UploadedFile::fake()->image('example.png')])->assertOk()->assertExactJson(['fields' => ['name' => 'Example Citizen', 'dob' => '1985-01-01', 'state' => '9', 'district' => '173'], 'identity_verified' => false])->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
