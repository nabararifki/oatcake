<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LangParityTest extends TestCase
{
    public function test_all_language_files_and_keys_in_id_and_en_have_strict_parity(): void
    {
        $idDir = lang_path('id');
        $enDir = lang_path('en');

        $this->assertDirectoryExists($idDir, 'Directory lang/id does not exist.');
        $this->assertDirectoryExists($enDir, 'Directory lang/en does not exist.');

        $idFiles = collect(File::files($idDir))->map(fn ($f) => $f->getFilename())->sort()->values()->all();
        $enFiles = collect(File::files($enDir))->map(fn ($f) => $f->getFilename())->sort()->values()->all();

        $this->assertSame($idFiles, $enFiles, 'Language file lists in lang/id and lang/en do not match.');
        $this->assertNotEmpty($idFiles, 'Language directories must not be empty.');

        $requiredDictionaries = [
            'attendance.php',
            'auth.php',
            'common.php',
            'company.php',
            'dashboard.php',
            'departments.php',
            'nav.php',
            'roster.php',
            'staff.php',
            'table.php',
        ];

        foreach ($requiredDictionaries as $requiredFile) {
            $this->assertContains($requiredFile, $idFiles, "Required dictionary {$requiredFile} is missing in lang/id.");
            $this->assertContains($requiredFile, $enFiles, "Required dictionary {$requiredFile} is missing in lang/en.");
        }

        foreach ($idFiles as $filename) {
            $idData = require $idDir.DIRECTORY_SEPARATOR.$filename;
            $enData = require $enDir.DIRECTORY_SEPARATOR.$filename;

            $idKeys = $this->flattenKeys($idData);
            $enKeys = $this->flattenKeys($enData);

            $missingInEn = array_values(array_diff($idKeys, $enKeys));
            $missingInId = array_values(array_diff($enKeys, $idKeys));

            $this->assertEmpty(
                $missingInEn,
                "Keys present in lang/id/{$filename} but missing in lang/en/{$filename}: ".implode(', ', $missingInEn)
            );

            $this->assertEmpty(
                $missingInId,
                "Keys present in lang/en/{$filename} but missing in lang/id/{$filename}: ".implode(', ', $missingInId)
            );

            foreach ($idKeys as $dotKey) {
                $idVal = data_get($idData, $dotKey);
                $enVal = data_get($enData, $dotKey);
                $this->assertIsString($idVal, "Value for lang/id/{$filename}:{$dotKey} must be a string.");
                $this->assertIsString($enVal, "Value for lang/en/{$filename}:{$dotKey} must be a string.");
                $this->assertNotSame('', trim($idVal), "Value for lang/id/{$filename}:{$dotKey} must not be empty.");
                $this->assertNotSame('', trim($enVal), "Value for lang/en/{$filename}:{$dotKey} must not be empty.");
            }
        }
    }

    public function test_all_translation_keys_referenced_in_views_and_app_exist_in_id_and_en(): void
    {
        $idDir = lang_path('id');
        $enDir = lang_path('en');

        $idDict = [];
        $enDict = [];

        foreach (File::files($idDir) as $file) {
            $group = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            $idDict[$group] = require $file->getPathname();
        }

        foreach (File::files($enDir) as $file) {
            $group = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            $enDict[$group] = require $file->getPathname();
        }

        $scanDirs = [resource_path('views'), app_path()];
        $missingId = [];
        $missingEn = [];

        foreach ($scanDirs as $dir) {
            foreach (File::allFiles($dir) as $file) {
                $content = file_get_contents($file->getPathname());
                if (preg_match_all('/(?:__|@lang|trans)\(\s*[\'"]([a-zA-Z0-9_-]+)\.([a-zA-Z0-9_.-]+)[\'"]/', $content, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $group = $match[1];
                        $key = $match[2];
                        $fullKey = "{$group}.{$key}";

                        if (data_get($idDict[$group] ?? [], $key) === null) {
                            $missingId[$fullKey] = $file->getRelativePathname();
                        }
                        if (data_get($enDict[$group] ?? [], $key) === null) {
                            $missingEn[$fullKey] = $file->getRelativePathname();
                        }
                    }
                }
            }
        }

        $this->assertEmpty(
            $missingId,
            'Missing translation keys in lang/id: '.json_encode($missingId)
        );

        $this->assertEmpty(
            $missingEn,
            'Missing translation keys in lang/en: '.json_encode($missingEn)
        );
    }

    /**
     * Flatten nested array keys recursively using dot notation.
     *
     * @param  array<string, mixed>  $array
     * @return list<string>
     */
    private function flattenKeys(array $array, string $prefix = ''): array
    {
        $keys = [];

        foreach ($array as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $keys = array_merge($keys, $this->flattenKeys($value, $fullKey));
            } else {
                $keys[] = $fullKey;
            }
        }

        sort($keys);

        return $keys;
    }
}
