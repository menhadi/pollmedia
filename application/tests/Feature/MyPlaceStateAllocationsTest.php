<?php

namespace Tests\Feature;

use App\Services\MyPlacePanchayat;
use Tests\TestCase;

class MyPlaceStateAllocationsTest extends TestCase
{
    public function test_allocations_require_official_directory_selection_and_do_not_change_vouchers(): void
    {
        $service = app(MyPlacePanchayat::class);
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '173'], 'village' => null];
        $before = $service->reports($context);
        $data = $service->data($context, '81720');
        $this->assertCount(3, $data['allocations']);
        $this->assertSame('2021-2022', $data['allocations'][0]['year']);
        $this->assertSame('INR', $data['allocations'][0]['unit']);
        $this->assertCount(12, $data['allocations'][0]['installments']);
        $this->assertNull($data['allocations'][1]['unit']);
        $this->assertSame($before, $service->reports($context));
        $this->assertSame([], $service->data($context, '999999')['allocations']);
        $context['village'] = ['code' => '999999'];
        $this->assertSame([], $service->data($context, '81720')['allocations']);
        $context['state']['code'] = '30';
        $this->assertSame([], $service->data($context, '81720')['allocations']);
    }

    public function test_rendering_keeps_source_amounts_missing_values_and_unknown_units_separate(): void
    {
        $context = ['state' => ['code' => '9'], 'district' => ['code' => '173'], 'village' => null];
        $data = app(MyPlacePanchayat::class)->data($context, '81720');
        $data['allocations'][0]['installments'][0]['amount'] = null;
        $data['allocations'][0]['installments'][1]['amount'] = '0';
        foreach (['en', 'hi'] as $language) {
            $html = view('my-place-panchayat-allocations', ['panchayat' => $data, 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en])->render();
            $this->assertSame(22, substr_count($html, 'data-state-allocation'));
            $this->assertStringContainsString($language === 'en' ? 'Unavailable' : 'उपलब्ध नहीं', $html);
            $this->assertStringContainsString('<td>0</td>', $html);
            $this->assertStringContainsString('GP sfc 10+11th Installment', $html);
            $this->assertStringContainsString($language === 'en' ? 'Source units unconfirmed' : 'स्रोत की इकाई अपुष्ट', $html);
            $this->assertStringNotContainsString('gp-chart-month', $html);
            $this->assertStringNotContainsString('₹', $html);
        }
    }

    public function test_changed_shard_hash_is_rejected(): void
    {
        $root = sys_get_temp_dir().'/allocation-'.bin2hex(random_bytes(8));
        mkdir($root);
        file_put_contents($root.'/manifest.json', json_encode(['schema' => 1, 'shards' => ['20' => str_repeat('0', 64)]]));
        file_put_contents($root.'/20.json', '{"81720":[]}');
        config(['services.egramswaraj.allocations_path' => $root]);
        try {
            $context = ['state' => ['code' => '9'], 'district' => ['code' => '173'], 'village' => null];
            $this->assertSame([], app(MyPlacePanchayat::class)->data($context, '81720')['allocations']);
        } finally {
            unlink($root.'/manifest.json');
            unlink($root.'/20.json');
            rmdir($root);
        }
    }
}
