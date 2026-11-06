<?php

namespace Tests\Unit;

use App\Services\ConstituencyUrls;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ConstituencyUrlsTest extends TestCase
{
    public function test_named_paths_preserve_source_seat_and_report_filters(): void
    {
        $this->app['router']->get('/india/constituencies/{kind}/{state}/{name}', fn () => '')->name('constituency.named');
        $this->app['router']->getRoutes()->refreshNameLookups();
        Cache::put('constituency-url-names-v1-'.sha1('ac|Tamil Nadu'), ['Dr. Radhakrishnan Nagar'], 600);
        Cache::put('constituency-url-names-v1-'.sha1('pc|Tamil Nadu'), ['Chennai North'], 600);
        $path = ConstituencyUrls::url(['kind' => 'ac', 'state' => 'Tamil Nadu', 'name' => 'Dr. Radhakrishnan Nagar', 'edition' => str_repeat('a', 24), 'code' => 11, 'format' => 'report'], false);
        $this->assertStringStartsWith('/india/constituencies/ac/tamil-nadu/dr-radhakrishnan-nagar?', $path);
        parse_str(parse_url($path, PHP_URL_QUERY), $query);
        $this->assertSame(['edition' => str_repeat('a', 24), 'code' => '11', 'format' => 'report'], $query);
    }

    public function test_state_aliases_share_the_same_named_url(): void
    {
        $this->app['router']->get('/india/constituencies/{kind}/{state}/{name}', fn () => '')->name('constituency.named');
        $this->app['router']->getRoutes()->refreshNameLookups();
        Cache::put('constituency-url-names-v1-'.sha1('ac|Tamil Nadu'), ['Dr. Radhakrishnan Nagar'], 600);
        Cache::put('constituency-url-names-v1-'.sha1('pc|Tamil Nadu'), ['Chennai North'], 600);
        $this->assertSame(ConstituencyUrls::url(['kind' => 'pc', 'state' => 'Tamil Nadu', 'name' => 'Chennai North'], false), ConstituencyUrls::url(['kind' => 'pc', 'state' => 'Madras', 'name' => 'Chennai North'], false));
    }

    public function test_punctuation_namesakes_do_not_share_a_url(): void
    {
        $this->app['router']->get('/india/constituencies/{kind}/{state}/{name}', fn () => '')->name('constituency.named');
        $this->app['router']->getRoutes()->refreshNameLookups();
        Cache::put('constituency-url-names-v1-'.sha1('ac|Goa'), ['Vasco-da-gama', 'Vasco Da Gama'], 600);
        $this->assertNotSame(ConstituencyUrls::url(['kind' => 'ac', 'state' => 'Goa', 'name' => 'Vasco-da-gama'], false), ConstituencyUrls::url(['kind' => 'ac', 'state' => 'Goa', 'name' => 'Vasco Da Gama'], false));
    }
}
