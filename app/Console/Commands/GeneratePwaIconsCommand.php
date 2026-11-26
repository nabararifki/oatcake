<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;

class GeneratePwaIconsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pwa:generate-icons {--source= : Optional path to source square logo image}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate standard PWA PNG icons (192x192, 512x512, maskable 512x512, apple-touch-icon 180x180) in public/icons/';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sourceOption = $this->option('source');
        $candidates = array_filter([
            is_string($sourceOption) && $sourceOption !== '' ? $sourceOption : null,
            storage_path('app/public/oatcake_logo_min.png'),
            storage_path('app/public/oatcake_logo_full.jpg'),
        ]);

        $sourcePath = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $sourcePath = $candidate;
                break;
            }
        }

        $outputDir = public_path('icons');
        File::ensureDirectoryExists($outputDir);

        $manager = ImageManager::usingDriver(GdDriver::class);

        $targets = [
            ['filename' => 'icon-192x192.png', 'size' => 192, 'maskable' => false],
            ['filename' => 'icon-512x512.png', 'size' => 512, 'maskable' => false],
            ['filename' => 'icon-maskable-512x512.png', 'size' => 512, 'maskable' => true],
            ['filename' => 'apple-touch-icon.png', 'size' => 180, 'maskable' => false],
        ];

        foreach ($targets as $target) {
            $size = $target['size'];
            $destPath = $outputDir.DIRECTORY_SEPARATOR.$target['filename'];

            if ($sourcePath !== null) {
                $base = $manager->decodePath($sourcePath);
            } else {
                $base = $manager->createImage($size, $size)->fill('#ffffff');
            }

            if ($target['maskable']) {
                // Android adaptive icon safe-zone: keep logo within ~68% centered inside #ffffff canvas.
                $innerSize = (int) round($size * 0.68);
                $inner = $base->cover($innerSize, $innerSize, 'center');
                $canvas = $manager->createImage($size, $size)->fill('#ffffff');
                $canvas->insert($inner, alignment: 'center');
                $outputImage = $canvas;
            } else {
                $canvas = $manager->createImage($size, $size)->fill('#ffffff');
                $canvas->insert($base->cover($size, $size, 'center'), alignment: 'center');
                $outputImage = $canvas;
            }

            $binary = (string) $outputImage->encodeUsingFormat(Format::PNG);
            File::put($destPath, $binary);

            $this->info(sprintf('Generated %s (%dx%d, %d bytes)', $destPath, $size, $size, strlen($binary)));
        }

        return self::SUCCESS;
    }
}
