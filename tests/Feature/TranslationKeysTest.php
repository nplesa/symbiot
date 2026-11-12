<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TranslationKeysTest extends TestCase
{
    private const LOCALES = ['en', 'ro', 'it'];

    /**
     * Keys of the hidden Phone/Wifi/Camera/iDrone cards in home.blade.php, which have no JS
     * and are due for removal (probleme.txt, point 70). Drop this list together with the cards.
     */
    private const PENDING_REMOVAL = [
        'Phone', 'Activate target', 'Configure', 'Approach', 'Listen', 'Record', 'Wifi', 'Activate Wifi',
        'Connect', 'Disconnect', 'Check', 'Camera', 'Detect Cameras', 'Acquire', 'Remove', 'iDrone System',
        'Activate', 'Set Target', 'Control', 'Destroy', 'Download Data',
    ];

    /** @return array<string, list<string>> key => views using it */
    private function viewKeys(): array
    {
        $keys = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $source = $file->getContents();
            preg_match_all("/(?:__|@lang|trans)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $single);
            preg_match_all('/(?:__|@lang|trans)\\(\\s*"((?:[^"\\\\]|\\\\.)*)"/', $source, $double);
            foreach ([...$single[1], ...$double[1]] as $key) {
                $key = stripslashes($key);
                // Group keys such as auth.failed live in the PHP language files.
                if (! preg_match('/^[a-z_]+\.[a-z_.]+$/', $key)) {
                    $keys[$key][] = $file->getRelativePathname();
                }
            }
        }

        return $keys;
    }

    /** @return array<string, array<string, string>> */
    private function dictionaries(): array
    {
        $dictionaries = [];
        foreach (self::LOCALES as $locale) {
            $dictionaries[$locale] = json_decode(File::get(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
        }

        return $dictionaries;
    }

    public function test_every_translated_view_string_exists_in_every_language(): void
    {
        $dictionaries = $this->dictionaries();
        $missing = [];

        foreach ($this->viewKeys() as $key => $views) {
            if (in_array($key, self::PENDING_REMOVAL, true)) {
                continue;
            }
            foreach ($dictionaries as $locale => $dictionary) {
                if (! array_key_exists($key, $dictionary)) {
                    $missing[] = "{$locale}: \"{$key}\" (" . implode(', ', array_unique($views)) . ')';
                }
            }
        }

        $this->assertSame([], $missing);
    }

    public function test_all_language_files_have_the_same_keys(): void
    {
        $dictionaries = $this->dictionaries();
        $reference = array_keys($dictionaries['en']);
        sort($reference);

        foreach ($dictionaries as $locale => $dictionary) {
            $keys = array_keys($dictionary);
            sort($keys);
            $this->assertSame($reference, $keys, "{$locale}.json keys differ from en.json");
        }
    }
}
