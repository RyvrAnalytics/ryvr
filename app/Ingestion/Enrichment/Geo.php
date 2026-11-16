<?php

namespace App\Ingestion\Enrichment;

use GeoIp2\Database\Reader;
use Illuminate\Http\Request;
use Throwable;

/**
 * Country, region and city for an IP. The IP itself is never stored.
 *
 * Sources, in order: a CDN country header (when trusted), then a local .mmdb database.
 */
class Geo
{
    private ?Reader $reader = null;

    private bool $readerTried = false;

    /**
     * @return array{country: string, region: string, city: string}
     */
    public function locate(string $ip, Request $request): array
    {
        $result = ['country' => '', 'region' => '', 'city' => ''];

        if ($reader = $this->reader()) {
            try {
                $city = $reader->city($ip);
                $result = [
                    'country' => (string) $city->country->isoCode,
                    'region' => (string) ($city->mostSpecificSubdivision->name ?? ''),
                    'city' => (string) ($city->city->name ?? ''),
                ];
            } catch (Throwable) {
                // Unknown or private address: leave it blank.
            }
        }

        if ($result['country'] === '' && config('ryvr.geoip.trust_cdn_country')) {
            $header = strtoupper((string) $request->header('CF-IPCountry', $request->header('X-Country-Code', '')));
            if (preg_match('/^[A-Z]{2}$/', $header) && ! in_array($header, ['XX', 'T1'], true)) {
                $result['country'] = $header;
            }
        }

        return $result;
    }

    private function reader(): ?Reader
    {
        if (! $this->readerTried) {
            $this->readerTried = true;
            $path = config('ryvr.geoip.database');
            if ($path && is_readable($path)) {
                try {
                    $this->reader = new Reader($path);
                } catch (Throwable) {
                    $this->reader = null;
                }
            }
        }

        return $this->reader;
    }
}
