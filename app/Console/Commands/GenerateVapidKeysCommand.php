<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;

class GenerateVapidKeysCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'webpush:vapid {--store : Persist the generated keypair to local private storage fallback}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate an EC P-256 (prime256v1) VAPID keypair for W3C Web Push notifications';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $keyPair = WebPushService::generateVapidKeyPair();

        if ($this->option('store')) {
            WebPushService::storeFallbackKeyPair($keyPair);
        }

        $this->info('VAPID EC P-256 keypair generated successfully:');
        $this->line('VAPID_PUBLIC_KEY='.$keyPair['public_key']);
        $this->line('VAPID_PRIVATE_KEY='.$keyPair['private_key']);
        $this->line('VAPID_SUBJECT=mailto:admin@oatcake.local');

        return self::SUCCESS;
    }
}
