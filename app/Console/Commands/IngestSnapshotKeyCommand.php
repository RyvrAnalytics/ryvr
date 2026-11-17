<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ryvr:ingest:snapshot-key')]
#[Description('Generate an Ed25519 key pair for signing the ingestion config snapshot')]
class IngestSnapshotKeyCommand extends Command
{
    public function handle(): int
    {
        $pair = sodium_crypto_sign_keypair();

        $this->line('Put this in the app .env (keep it secret):');
        $this->line('RYVR_INGEST_SNAPSHOT_SIGNING_KEY='.base64_encode(sodium_crypto_sign_secretkey($pair)));
        $this->newLine();
        $this->line('Give the collector this public key:');
        $this->line(base64_encode(sodium_crypto_sign_publickey($pair)));

        return self::SUCCESS;
    }
}
