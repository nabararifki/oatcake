<?php

namespace App\Services;

use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;

class AttendancePhotoService
{
    /**
     * Allowed real MIME types for attendance selfie proofs.
     *
     * @var list<string>
     */
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * Maximum bounding dimension in pixels (preserves aspect ratio).
     */
    public const MAX_DIMENSION_PX = 1280;

    /**
     * Maximum compressed file size in bytes (250 KB).
     */
    public const MAX_SIZE_BYTES = 256000;

    /**
     * Default WebP compression quality (70-75%).
     */
    public const DEFAULT_QUALITY = 75;

    /**
     * Validate, sanitize EXIF/payloads, resize, compress to <= 250 KB WebP, and store in tenant-isolated private storage.
     *
     * @throws ValidationException
     */
    public function processAndStore(UploadedFile $file, Company|int $company, string $employeeUuid): string
    {
        $companyModel = $company instanceof Company ? $company : Company::withoutGlobalScopes()->find($company);
        $companyId = $companyModel ? (int) $companyModel->id : (int) $company;

        if ($companyModel && $companyModel->isStorageLimitExceeded($file->getSize() ?: 0)) {
            throw ValidationException::withMessages([
                'photo' => [__('attendance.err_storage_quota_exceeded', ['limit' => $companyModel->storage_limit_mb ?? 1024])],
            ]);
        }

        $realPath = $file->getRealPath();
        if ($realPath === false || ! is_file($realPath)) {
            throw ValidationException::withMessages([
                'photo' => [__('attendance.selfie_required')],
            ]);
        }

        // 1. Validasi MIME riil dari header biner berkas
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string) $finfo->file($realPath);

        if (! in_array($detectedMime, self::ALLOWED_MIME_TYPES, true)) {
            throw ValidationException::withMessages([
                'photo' => ['Format berkas foto tidak valid. Gunakan JPEG, PNG, atau WebP.'],
            ]);
        }

        // 2. Validasi keamanan: cegah eksekusi skrip PHP yang terselubung di metadata EXIF / raw bytes
        $rawBytes = (string) file_get_contents($realPath);
        if (preg_match('/<\?(?:php|=)|<script\b/i', $rawBytes) === 1) {
            throw ValidationException::withMessages([
                'photo' => ['Berkas gambar ditolak karena mengandung metadata berbahaya.'],
            ]);
        }

        $imageInfo = @getimagesize($realPath);
        if ($imageInfo === false || ($imageInfo[0] ?? 0) < 1 || ($imageInfo[1] ?? 0) < 1) {
            throw ValidationException::withMessages([
                'photo' => ['Berkas bukan merupakan gambar yang valid.'],
            ]);
        }

        // 3. Decode & Re-encode menggunakan Intervention Image v4 (GD Driver) — otomatis membersihkan EXIF
        try {
            $manager = ImageManager::usingDriver(GdDriver::class);
            $image = $manager->decodePath($realPath);
            $image->scaleDown(self::MAX_DIMENSION_PX, self::MAX_DIMENSION_PX);

            $format = function_exists('imagewebp') ? Format::WEBP : Format::JPEG;
            $extension = $format === Format::WEBP ? 'webp' : 'jpg';

            $qualities = [self::DEFAULT_QUALITY, 65, 50, 35];
            $binaryOutput = '';

            foreach ($qualities as $quality) {
                $encoded = $image->encodeUsingFormat($format, quality: $quality);
                $binaryOutput = (string) $encoded;

                if (strlen($binaryOutput) <= self::MAX_SIZE_BYTES) {
                    break;
                }
            }

            // Fallback extra scale down jika gambar sangat padat dan masih > 250 KB
            if (strlen($binaryOutput) > self::MAX_SIZE_BYTES) {
                $image->scaleDown(800, 800);
                $binaryOutput = (string) $image->encodeUsingFormat($format, quality: 50);
            }
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'photo' => ['Gagal memproses berkas swafoto. Pastikan berkas gambar tidak rusak.'],
            ]);
        }

        // 4. Penyimpanan terisolasi per company_id di storage/app/private/attendance_proofs/{company_id}/{YYYY-MM}/
        $yearMonth = Carbon::now('Asia/Jakarta')->format('Y-m');
        $uniqueHash = hash('sha256', $employeeUuid . '|' . Str::uuid()->toString() . '|' . microtime(true));
        $shortHash = substr($uniqueHash, 0, 32);

        $relativePath = "attendance_proofs/{$companyId}/{$yearMonth}/{$shortHash}.{$extension}";

        if ($companyModel && $companyModel->isStorageLimitExceeded(strlen($binaryOutput))) {
            throw ValidationException::withMessages([
                'photo' => [__('attendance.err_storage_quota_exceeded', ['limit' => $companyModel->storage_limit_mb ?? 1024])],
            ]);
        }

        Storage::disk('local')->put($relativePath, $binaryOutput);

        return $relativePath;
    }
}
