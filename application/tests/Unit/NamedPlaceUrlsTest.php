<?php

namespace Tests\Unit;

use App\Services\NamedPlaceUrls;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class NamedPlaceUrlsTest extends TestCase
{
    public function test_duplicate_names_keep_distinct_stable_identities(): void
    {
        $first = NamedPlaceUrls::path('villages', 'Uttar Pradesh', 'Pilibhit', 'Rampur', 101);
        $second = NamedPlaceUrls::path('villages', 'Uttar Pradesh', 'Pilibhit', 'Rampur', 102);
        self::assertNotSame($first, $second);
        self::assertStringContainsString('/uttar-pradesh/pilibhit/rampur-', $first);
    }

    public function test_census_and_lgd_identifiers_cannot_share_a_url(): void
    {
        self::assertNotSame(NamedPlaceUrls::path('villages', 'Goa', 'North Goa', 'Virnora', 123), NamedPlaceUrls::path('lgd-village', 'Goa', 'North Goa', 'Virnora', 123));
    }

    public function test_reserved_characters_are_not_path_separators(): void
    {
        $path = NamedPlaceUrls::path('subdistricts', 'Jammu & Kashmir', 'A/B', 'Name?x=1', 42);
        self::assertStringNotContainsString('?', $path);
        self::assertStringContainsString('/jammu-kashmir/ab/', $path);
        self::assertStringEndsWith('-42', $path);
    }

    public function test_nonnumeric_identity_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NamedPlaceUrls::path('villages','State','District','Name','1/other');
    }
}
