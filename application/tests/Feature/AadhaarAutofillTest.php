<?php

namespace Tests\Feature;

use App\Services\AadhaarAutofill;
use App\Services\MyPlaceDirectory;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AadhaarAutofillTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $directory = $this->mock(MyPlaceDirectory::class);
        $directory->shouldReceive('states')->andReturn([['code' => '9', 'name' => 'Uttar Pradesh']]);
        $directory->shouldReceive('districts')->with('9')->andReturn([['code' => '173', 'name' => 'Pilibhit']]);
    }

    public function test_only_known_editable_fields_are_extracted_and_full_aadhaar_number_is_discarded(): void
    {
        $fields = app(AadhaarAutofill::class)->parse("Government of India\nName: Example Citizen\nDOB: 01/01/1985\nPilibhit Uttar Pradesh 262001\n1234 5678 9012");
        $this->assertSame(['dob' => '1985-01-01', 'pincode' => '262001', 'name' => 'Example Citizen', 'state' => '9', 'district' => '173'], $fields);
        $this->assertStringNotContainsString('1234', json_encode($fields));
    }

    public function test_birth_year_does_not_invent_a_date_and_unclear_or_non_card_text_is_not_guessed(): void
    {
        $reader = app(AadhaarAutofill::class);
        $this->assertSame(['birth_year' => '1960'], $reader->parse('UIDAI Year of Birth: 1960'));
        $this->assertSame([], $reader->parse('Example Citizen 262001'));
        $this->assertArrayNotHasKey('dob', $reader->parse('UIDAI DOB: 31/02/1985'));
        $this->assertSame('1985-01-01', $reader->parse('आधार जन्म तिथि: ०१/०१/१९८५')['dob']);
        $this->assertSame('राम कुमार', $reader->parse("आधार\nनाम: राम कुमार\nजन्म तिथि: ०१/०१/१९८५")['name']);
    }

    public function test_temporary_photo_is_deleted_even_when_ocr_cannot_read_it(): void
    {
        $file = UploadedFile::fake()->createWithContent('unreadable.png', 'not an image');
        $path = $file->getRealPath();

        $this->assertSame([], app(AadhaarAutofill::class)->read($file));
        $this->assertFileDoesNotExist($path);
    }
}
