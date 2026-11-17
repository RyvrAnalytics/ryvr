<?php

namespace App\Console\Commands;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

#[Signature('ryvr:geoip:update {--path= : Where to store the database (default: RYVR_GEOIP_DATABASE or storage/app/geoip/city.mmdb)}')]
#[Description('Download the free DB-IP City Lite database (CC BY 4.0) used for country and city lookups')]
class GeoIpUpdateCommand extends Command
{
    public function handle(): int
    {
        $path = $this->option('path') ?: (config('ryvr.geoip.database') ?: storage_path('app/geoip/city.mmdb'));
        File::ensureDirectoryExists(dirname($path));

        // DB-IP publishes a new free build each month; fall back to last month early in the month.
        foreach ([now(), now()->subMonthNoOverflow()] as $month) {
            $url = sprintf('https://download.db-ip.com/free/dbip-city-lite-%s.mmdb.gz', $month->format('Y-m'));
            $this->components->info("Downloading {$url}");

            try {
                $response = Http::timeout(300)->withOptions(['sink' => $path.'.gz.tmp'])->get($url);
            } catch (Throwable $e) {
                $this->components->warn($e->getMessage());

                continue;
            }

            if (! $response->successful()) {
                $this->components->warn("Not available yet (HTTP {$response->status()}).");

                continue;
            }

            $data = gzdecode((string) file_get_contents($path.'.gz.tmp'));
            @unlink($path.'.gz.tmp');
            if ($data === false) {
                $this->components->error('The download was not a valid gzip file.');

                return self::FAILURE;
            }

            File::put($path.'.tmp', $data);

            try {
                (new Reader($path.'.tmp'))->city('1.1.1.1');
            } catch (AddressNotFoundException) {
                // A valid database may not know this address; the file opened fine.
            } catch (Throwable $e) {
                @unlink($path.'.tmp');
                $this->components->error('The database failed to open: '.$e->getMessage());

                return self::FAILURE;
            }

            rename($path.'.tmp', $path);
            $this->components->info("Saved to {$path}. Set RYVR_GEOIP_DATABASE={$path} if it is not set.");

            return self::SUCCESS;
        }

        $this->components->error('Could not download the database.');

        return self::FAILURE;
    }
}
