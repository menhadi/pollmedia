<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;

class OfficialDownload
{
    public function validateUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $approved = collect(config('imports.official_host_suffixes', []))
            ->contains(fn ($suffix) => $host === $suffix || str_ends_with($host, '.'.$suffix));
        $approved = $approved || DB::table('official_source_hosts')->where('host', $host)->where('enabled', true)->exists();
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! $approved || filter_var($host, FILTER_VALIDATE_IP)
            || preg_match('/(?:api[_-]?key|token|password|secret)=/i', $parts['query'] ?? '')) {
            throw new RuntimeException('Use a public HTTPS URL on an approved official source host, without credentials or API keys.');
        }

        return $host;
    }

    public function get(string $url): string
    {
        $host = $this->validateUrl($url);
        $addresses = gethostbynamel($host) ?: [];
        if ($addresses === [] || collect($addresses)->contains(fn ($ip) => ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            throw new RuntimeException('Official host could not be resolved to a public address.');
        }
        $maximum = config('imports.max_bytes');
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->windowsDownload($url, $host, $addresses[0], $maximum);
        }
        $bundle = config('source-monitor.ca_bundle');
        $response = Http::connectTimeout(10)->timeout(45)->withOptions([
            'allow_redirects' => false, 'verify' => $bundle && is_file($bundle) ? $bundle : true,
            'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$addresses[0]]],
            'progress' => function ($total, $received) use ($maximum): void {
                if ($total > $maximum || $received > $maximum) {
                    throw new RuntimeException('Official file exceeds the 20 MB download limit.');
                }
            },
        ])->get($url);
        if ($response->redirect()) {
            throw new RuntimeException('Source redirected. Update the connector to the final official file/API URL after checking it.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('Official source is unavailable or requires access approval. Upload the official export if necessary.');
        }
        $body = $response->body();
        if (strlen($body) > $maximum) {
            throw new RuntimeException('Official file exceeds the 20 MB download limit.');
        }
        if (preg_match('/^\s*(?:<!doctype html|<html)/i', $body)) {
            throw new RuntimeException('URL returned a webpage or CAPTCHA rather than data. Use its direct download URL or upload the official file.');
        }

        return $body;
    }

    private function windowsDownload(string $url, string $host, string $address, int $maximum): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'pollmedia-import-');
        if ($temporary === false) {
            throw new RuntimeException('Could not create the download buffer.');
        }
        try {
            $process = new Process(['curl.exe', '--silent', '--show-error', '--proto', '=https', '--connect-timeout', '10', '--max-time', '45',
                '--max-filesize', (string) $maximum, '--resolve', $host.':443:'.$address, '--output', $temporary, '--write-out', '%{http_code}', $url]);
            $process->setTimeout(50);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Official download failed certificate, connection or size checks. Verification remains enabled; upload the official export if needed.');
            }
            $status = (int) $process->getOutput();
            if ($status >= 300 && $status < 400) {
                throw new RuntimeException('Source redirected. Configure its final official download URL.');
            }
            if ($status < 200 || $status >= 300 || filesize($temporary) > $maximum) {
                throw new RuntimeException('Official source is unavailable, restricted or exceeds the size limit.');
            }
            $body = file_get_contents($temporary);
            if ($body === false || preg_match('/^\s*(?:<!doctype html|<html)/i', $body)) {
                throw new RuntimeException('URL returned a webpage or CAPTCHA instead of data. Use the direct export URL or upload the official file.');
            }

            return $body;
        } finally {
            unlink($temporary);
        }
    }
}
