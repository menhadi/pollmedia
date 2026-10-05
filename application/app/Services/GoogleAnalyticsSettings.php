<?php

namespace App\Services;

class GoogleAnalyticsSettings
{
    public function current(): array
    {
        return array_replace(['enabled' => false, 'measurement_id' => ''], app(SiteSettings::class)->get('google_analytics', []));
    }

    public function save(bool $enabled, ?string $measurementId): void
    {
        app(SiteSettings::class)->save('google_analytics', ['enabled' => $enabled, 'measurement_id' => $measurementId ?? ''], 'Google Analytics settings updated');
    }
}
