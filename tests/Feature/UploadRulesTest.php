<?php

namespace Tests\Feature;

use App\Support\UploadRules;
use Tests\TestCase;

/**
 * One definition of what may be uploaded where.
 *
 * The Storage screen tells staff "JPEG, PNG or WebP, up to 12 MB". That
 * sentence is only worth printing if the upload field enforces the same
 * thing, so the guard below is the point of this file: no form may go back to
 * writing its own number.
 */
class UploadRulesTest extends TestCase
{
    public function test_no_upload_field_hard_codes_its_own_limits(): void
    {
        $offenders = [];

        foreach ($this->filamentFiles() as $file) {
            $source = file_get_contents($file);

            // A literal rather than a call: ->maxSize(8192) or
            // ->acceptedFileTypes(['image/jpeg', ...]).
            if (preg_match('/->maxSize\(\s*\d/', $source)
                || preg_match('/->acceptedFileTypes\(\s*\[/', $source)) {
                $offenders[] = str_replace(base_path().'/', '', $file);
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These read their own upload limits instead of App\Support\UploadRules,',
            'so the Storage screen will describe something the form does not enforce:',
            ...$offenders,
        ]));
    }

    public function test_every_rule_describes_itself_completely(): void
    {
        foreach (UploadRules::all() as $key => $rule) {
            $this->assertNotSame('', $rule['label'], $key);
            $this->assertNotSame('', $rule['where'], $key);
            $this->assertNotEmpty($rule['types'], $key);
            $this->assertGreaterThan(0, $rule['max_kb'], $key);
        }
    }

    public function test_the_sentence_under_a_field_matches_what_it_enforces(): void
    {
        $this->assertSame('JPEG, PNG or WebP, up to 8 MB.', UploadRules::summary('deity_image'));
        $this->assertSame(8192, UploadRules::maxKbFor('deity_image'));
    }

    /** An unknown key must not silently widen what is accepted. */
    public function test_an_unknown_key_falls_back_to_images(): void
    {
        $this->assertSame(UploadRules::IMAGE_TYPES, UploadRules::typesFor('no-such-upload'));
    }

    public function test_the_validation_rule_covers_the_same_types(): void
    {
        $this->assertSame('jpeg,jpg,png,webp', UploadRules::mimesRuleFor('deity_image'));
    }

    /** @return array<int, string> */
    protected function filamentFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Filament')),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
