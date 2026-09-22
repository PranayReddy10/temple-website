<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\InitialsAvatarProvider;
use Tests\TestCase;

/**
 * The avatar must never depend on a network call.
 *
 * Filament's default points the img at ui-avatars.com, which means the
 * signed-in person's name is sent to a third party on every page load and
 * the avatar breaks whenever that host is slow or blocked.
 */
class InitialsAvatarProviderTest extends TestCase
{
    protected function avatarFor(string $name): string
    {
        return (new InitialsAvatarProvider)->get(new User(['name' => $name]));
    }

    protected function svgFor(string $name): string
    {
        $url = $this->avatarFor($name);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $url);

        return base64_decode(substr($url, strlen('data:image/svg+xml;base64,')));
    }

    public function test_the_avatar_is_inline_and_reaches_no_external_host(): void
    {
        $url = $this->avatarFor('Verify Admin');

        $this->assertStringNotContainsString('ui-avatars.com', $url);
        $this->assertStringNotContainsString('http', substr($url, strlen('data:image/svg+xml;base64,')));
    }

    public function test_it_uses_the_first_two_initials(): void
    {
        $this->assertStringContainsString('>VA<', $this->svgFor('Verify Admin'));
        $this->assertStringContainsString('>SP<', $this->svgFor('Sri Padmanabhaswamy Trust'));
        $this->assertStringContainsString('>K<', $this->svgFor('kashi'));
    }

    public function test_leading_punctuation_is_not_an_initial(): void
    {
        $this->assertStringContainsString('>SA<', $this->svgFor('[SYSTEM] Admin'));
    }

    public function test_a_nameless_account_still_gets_an_avatar(): void
    {
        $this->assertStringContainsString('>?<', $this->svgFor('   '));
    }

    /** A data URI cannot carry raw newlines, and a name cannot carry markup. */
    public function test_the_svg_is_a_single_line_with_the_name_escaped(): void
    {
        $svg = $this->svgFor('Verify Admin');

        $this->assertStringNotContainsString("\n", $svg);

        $this->assertStringNotContainsString('<script', $this->svgFor('<script>x</script> Admin'));
    }

    public function test_it_carries_the_temple_palette(): void
    {
        $svg = $this->svgFor('Verify Admin');

        $this->assertStringContainsString(config('brand.colors.saffron.hex'), $svg);
        $this->assertStringContainsString(config('brand.colors.kumkum.hex'), $svg);
    }
}
