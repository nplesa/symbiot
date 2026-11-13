<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TranslationKeysTest extends TestCase
{
    private const LOCALES = ['en', 'ro', 'it'];

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
            foreach ($dictionaries as $locale => $dictionary) {
                if (! array_key_exists($key, $dictionary)) {
                    $missing[] = "{$locale}: \"{$key}\" (" . implode(', ', array_unique($views)) . ')';
                }
            }
        }

        $this->assertSame([], $missing);
    }

    /** @return array<string, list<string>> key => scripts using it, for t('...') in resources/js */
    private function scriptKeys(): array
    {
        $keys = [];
        foreach (File::allFiles(resource_path('js')) as $file) {
            if ($file->getExtension() !== 'js') {
                continue;
            }
            $source = $file->getContents();
            preg_match_all("/(?<![\\w.])t\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $single);
            preg_match_all('/(?<![\\w.])t\\(\\s*"((?:[^"\\\\]|\\\\.)*)"/', $source, $double);
            foreach ([...$single[1], ...$double[1]] as $key) {
                $keys[stripslashes($key)][] = $file->getRelativePathname();
            }
        }

        return $keys;
    }

    public function test_every_translated_script_string_exists_in_every_language(): void
    {
        $dictionaries = $this->dictionaries();
        $keys = $this->scriptKeys();
        $this->assertNotEmpty($keys);
        $missing = [];

        foreach ($keys as $key => $scripts) {
            foreach ($dictionaries as $locale => $dictionary) {
                if (! array_key_exists($key, $dictionary)) {
                    $missing[] = "{$locale}: \"{$key}\" (" . implode(', ', array_unique($scripts)) . ')';
                }
            }
        }

        $this->assertSame([], $missing);
    }

    public function test_translations_keep_the_placeholders_of_their_key(): void
    {
        $dictionaries = $this->dictionaries();
        $broken = [];

        foreach ($dictionaries['en'] as $key => $value) {
            preg_match_all('/:[a-z_]+/i', $key, $expected);
            $expected = array_unique($expected[0]);
            sort($expected);
            foreach ($dictionaries as $locale => $dictionary) {
                preg_match_all('/:[a-z_]+/i', $dictionary[$key] ?? '', $actual);
                $actual = array_unique($actual[0]);
                sort($actual);
                if ($actual !== $expected) {
                    $broken[] = "{$locale}: \"{$key}\"";
                }
            }
        }

        $this->assertSame([], $broken);
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
