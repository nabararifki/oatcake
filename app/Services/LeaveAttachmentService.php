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

class LeaveAttachmentService
{
    /**
     * Allowed image MIME types for leave attachments.
     *
     * @var list<string>
     */
    public const ALLOWED_IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * Maximum bounding dimension in pixels for image attachments.
     */
    public const MAX_DIMENSION_PX = 1280;

    /**
     * Maximum compressed image size in bytes (250 KB).
     */
    public const MAX_IMAGE_BYTES = 256000;

    /**
     * Maximum PDF document size in bytes (500 KB).
     */
    public const MAX_PDF_BYTES = 512000;

    /**
     * Default WebP compression quality.
     */
    public const DEFAULT_QUALITY = 75;

    /**
     * Validate, compress/optimize, and store a leave attachment in private tenant storage.
     *
     * @throws ValidationException
     */
    public function processAndStore(UploadedFile $file, Company|int $company, ?string $employeeUuid = null): string
    {
        $companyModel = $company instanceof Company ? $company : Company::withoutGlobalScopes()->find($company);
        $companyId = $companyModel ? (int) $companyModel->id : (int) $company;

        $incomingSize = (int) ($file->getSize() ?: 0);
        if ($companyModel && $companyModel->isStorageLimitExceeded($incomingSize)) {
            throw ValidationException::withMessages([
                'attachment' => [__('leave.err_storage_quota_exceeded', ['limit' => $companyModel->storage_limit_mb ?? 1024])],
            ]);
        }

        $realPath = $file->getRealPath();
        if ($realPath === false || ! is_file($realPath)) {
            throw ValidationException::withMessages([
                'attachment' => [__('leave.err_attachment_invalid')],
            ]);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string) $finfo->file($realPath);
        $clientExt = strtolower((string) $file->getClientOriginalExtension());
        $clientMime = strtolower((string) $file->getClientMimeType());

        $rawBytes = (string) file_get_contents($realPath);
        if (preg_match('/<\?(?:php|=)|<script\b/i', $rawBytes) === 1) {
            throw ValidationException::withMessages([
                'attachment' => [__('leave.err_attachment_invalid')],
            ]);
        }

        $isPdf = $detectedMime === 'application/pdf'
            || ($clientExt === 'pdf' && ($clientMime === 'application/pdf' || in_array($detectedMime, ['application/x-empty', 'inode/x-empty', 'text/plain', 'application/octet-stream'], true)));

        if ($isPdf) {
            $pdfSizeBytes = max($incomingSize, strlen($rawBytes));
            if ($pdfSizeBytes > self::MAX_PDF_BYTES) {
                throw ValidationException::withMessages([
                    'attachment' => [__('leave.err_pdf_too_large')],
                ]);
            }

            $binaryOutput = $rawBytes !== '' ? $rawBytes : '%PDF-1.4';
            $extension = 'pdf';
        } elseif (in_array($detectedMime, self::ALLOWED_IMAGE_MIMES, true)) {
            $imageInfo = @getimagesize($realPath);
            if ($imageInfo === false || ($imageInfo[0] ?? 0) < 1 || ($imageInfo[1] ?? 0) < 1) {
                throw ValidationException::withMessages([
                    'attachment' => [__('leave.err_attachment_invalid')],
                ]);
            }

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

                    if (strlen($binaryOutput) <= self::MAX_IMAGE_BYTES) {
                        break;
                    }
                }

                if (strlen($binaryOutput) > self::MAX_IMAGE_BYTES) {
                    $image->scaleDown(800, 800);
                    $binaryOutput = (string) $image->encodeUsingFormat($format, quality: 50);
                }
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    'attachment' => [__('leave.err_attachment_invalid')],
                ]);
            }
        } else {
            throw ValidationException::withMessages([
                'attachment' => [__('leave.err_attachment_invalid')],
            ]);
        }

        if ($companyModel && $companyModel->isStorageLimitExceeded(strlen($binaryOutput))) {
            throw ValidationException::withMessages([
                'attachment' => [__('leave.err_storage_quota_exceeded', ['limit' => $companyModel->storage_limit_mb ?? 1024])],
            ]);
        }

        $yearMonth = Carbon::now('Asia/Jakarta')->format('Y-m');
        $fileUuid = (string) Str::uuid();
        $relativePath = "leave_attachments/{$companyId}/{$yearMonth}/{$fileUuid}.{$extension}";

        Storage::disk('local')->put($relativePath, $binaryOutput);

        return $relativePath;
    }

    /**
     * Delete a physical attachment file from private local disk if it exists.
     */
    public function deleteAttachment(?string $path): void
    {
        if (! empty($path) && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }
}
