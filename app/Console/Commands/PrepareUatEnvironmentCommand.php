<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Database\Seeders\UatMitraSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PrepareUatEnvironmentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'oatcake:prepare-uat';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Menyiapkan lingkungan UAT Mitra Novotel YIA (seeder idempoten, ikon PWA, kunci VAPID, dan ringkasan akun)';

    public function __construct(
        private readonly WebPushService $webPushService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('=== Menyiapkan Lingkungan UAT Mitra: Novotel Yogyakarta International Airport ===');

        // 1. Jalankan UatMitraSeeder (idempoten)
        $this->call('db:seed', [
            '--class' => UatMitraSeeder::class,
            '--force' => true,
        ]);

        // 2. Pastikan ikon PWA tersedia di public/icons/
        $requiredIcons = [
            'icon-192x192.png',
            'icon-512x512.png',
            'icon-maskable-512x512.png',
            'apple-touch-icon.png',
        ];
        $missingIcons = array_filter(
            $requiredIcons,
            static fn (string $icon): bool => ! File::exists(public_path("icons/{$icon}"))
        );
        if ($missingIcons !== []) {
            $this->call('pwa:generate-icons');
        }

        // 3. Pastikan pasangan kunci VAPID Web Push tersedia
        $vapidPublicKey = $this->webPushService->getVapidPublicKey();
        $this->info('VAPID Public Key aktif: '.substr($vapidPublicKey, 0, 24).'...');

        // 4. Tampilkan tabel akun login UAT lintas 5 peran RBAC
        $password = UatMitraSeeder::UAT_DEFAULT_PASSWORD;
        $this->table(
            ['Peran RBAC', 'Username', 'Email Login', 'Departemen', 'Password'],
            [
                ['super_admin', 'superadmin_uat', 'superadmin.uat@oatcake.test', 'Platform Global', $password],
                ['super_user', 'gm_uat', 'gm.uat@oatcake.test', 'FO (Executive)', $password],
                ['admin_hr', 'hr_uat', 'hr.uat@oatcake.test', 'HR (Talent & Culture)', $password],
                ['manager', 'fom_uat', 'fom.uat@oatcake.test', 'FO (MOD Eligible)', $password],
                ['manager', 'hkm_uat', 'hkm.uat@oatcake.test', 'HK (MOD Eligible)', $password],
                ['user', 'fo_staff1', 'fo.staff1@oatcake.test', 'FO (Mode A #1)', $password],
                ['user', 'fo_staff2', 'fo.staff2@oatcake.test', 'FO (Mode A #2)', $password],
                ['user', 'fo_staff3', 'fo.staff3@oatcake.test', 'FO (Tier-2 Ready)', $password],
                ['user', 'hk_staff1', 'hk.staff1@oatcake.test', 'HK (Outside Duty)', $password],
                ['user', 'fbs_staff1', 'fbs.staff1@oatcake.test', 'FBS', $password],
                ['user', 'eng_staff1', 'eng.staff1@oatcake.test', 'ENG', $password],
            ]
        );

        return self::SUCCESS;
    }
}
