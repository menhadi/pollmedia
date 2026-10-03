<?php

namespace Tests\Feature;

use App\Services\PrintableElectionMap;
use Tests\TestCase;

class PrintableElectionMapTest extends TestCase
{
    public function test_real_source_shapes_are_printable_and_only_the_matching_seat_is_highlighted(): void
    {
        foreach ([['pc', 'NCT OF Delhi', 'New Delhi'], ['ac', 'Uttar Pradesh', 'Puranpur']] as [$kind, $state, $name]) {
            $paths = app(PrintableElectionMap::class)->paths($kind, $state, $name);
            $this->assertNotEmpty($paths);
            $this->assertCount(1, array_filter($paths, fn ($path) => $path['selected']));
            foreach ($paths as $path) {
                $this->assertMatchesRegularExpression('/^M[0-9., LZM-]+$/', trim($path['path']));
            }
        }
        $this->assertSame([], app(PrintableElectionMap::class)->paths('pc', 'Unknown state'));
        $this->assertCount(0, array_filter(app(PrintableElectionMap::class)->paths('pc', 'Delhi', 'Unmatched historical name'), fn ($path) => $path['selected']));
    }
}
