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
        }
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
