<?php

namespace App\Http\Controllers;

use App\Ingestion\ConfigSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /internal/ingest/snapshot: the ingestion configuration for an external collector.
 *
 * Off unless RYVR_INGEST_SNAPSHOT_TOKEN is set. It hands out visitor salts and ingest keys,
 * so serve it only on a private network and keep the token secret.
 */
class IngestSnapshotController
{
    public function __invoke(Request $request, ConfigSnapshot $snapshot): Response
    {
        $token = (string) config('ryvr.ingest.snapshot_token');
        if ($token === '') {
            abort(404);
        }
        if (! hash_equals('Bearer '.$token, (string) $request->header('Authorization'))) {
            abort(401);
        }

        ['body' => $body, 'etag' => $etag] = $snapshot->build();
        $headers = ['ETag' => $etag, 'Cache-Control' => 'no-store'];

        if (in_array($etag, array_map('trim', explode(',', (string) $request->header('If-None-Match'))), true)) {
            return response('', 304, $headers);
        }

        if ($signature = $snapshot->sign($body)) {
            $headers['X-Ryvr-Signature'] = $signature;
        }

        return response($body, 200, $headers + ['Content-Type' => 'application/json']);
    }
}
