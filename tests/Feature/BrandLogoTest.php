<?php

namespace Tests\Feature;

use App\Support\BrandLogo;
use Tests\TestCase;

/**
 * Resolution of the brand mark embedded in generated PDFs.
 *
 * The case that matters is a candidate that EXISTS but cannot be used. That is
 * not hypothetical: on a web server without the GD extension, dompdf cannot
 * render a PNG at all, and an earlier version of BrandLogo found the PNG, gave
 * up, and never tried the JPEG installed beside it — so a documented fallback
 * list could not fall back. Every generated document silently printed the text
 * placeholder instead of the logo.
 *
 * GD cannot be unloaded at runtime, so the same path is exercised through an
 * unsupported EXTENSION, which is rejected by the identical "skip and keep
 * looking" branch.
 */
class BrandLogoTest extends TestCase
{
    /** @var array<int,string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        // Never leave test files in public/brand — a stray one would be picked
        // up by every subsequent render on this machine.
        foreach ($this->scratch as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    private function scratchFile(string $name, string $contents = 'x'): string
    {
        $path = public_path('brand/'.$name);
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $contents);
        $this->scratch[] = $path;

        return $path;
    }

    public function test_an_unusable_candidate_does_not_abort_the_search(): void
    {
        // Configured path wins on precedence and really exists, but .webp is not
        // in the supported mime map. The search must continue to the conventional
        // locations rather than concluding "no logo".
        $unusable = $this->scratchFile('logo-test.webp', 'not really a webp');
        config(['company.logo_path' => $unusable]);

        $uri = BrandLogo::dataUri();

        // Only meaningful if a real logo is installed to fall through TO.
        if (! is_file(public_path('brand/ponos-logo.png')) && ! is_file(public_path('brand/ponos-logo.jpg'))) {
            $this->markTestSkipped('No brand logo installed; nothing to fall through to.');
        }

        $this->assertNotNull($uri, 'An unusable configured logo must not suppress the installed one.');
        $this->assertStringStartsWith('data:image/', $uri);
    }

    public function test_a_missing_configured_path_falls_through(): void
    {
        config(['company.logo_path' => public_path('brand/__does-not-exist__.png')]);

        if (! is_file(public_path('brand/ponos-logo.png')) && ! is_file(public_path('brand/ponos-logo.jpg'))) {
            $this->markTestSkipped('No brand logo installed; nothing to fall through to.');
        }

        $this->assertNotNull(BrandLogo::dataUri());
    }

    public function test_the_configured_path_wins_when_it_is_usable(): void
    {
        // A one-pixel JPEG, so the assertion is about precedence rather than
        // whichever format happens to be installed.
        $jpeg = base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            .'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
            .'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='
        );
        $path = $this->scratchFile('logo-test-configured.jpg', $jpeg);
        config(['company.logo_path' => $path]);

        $uri = BrandLogo::dataUri();

        $this->assertNotNull($uri);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $uri);
        $this->assertSame('data:image/jpeg;base64,'.base64_encode($jpeg), $uri);
    }

    public function test_no_logo_anywhere_degrades_to_null_rather_than_throwing(): void
    {
        config(['company.logo_path' => public_path('brand/__does-not-exist__.png')]);

        // Can't remove the real files, so this only asserts the contract when the
        // machine genuinely has none installed.
        if (is_file(public_path('brand/ponos-logo.png')) || is_file(public_path('brand/ponos-logo.jpg'))) {
            $this->markTestSkipped('A real logo is installed; the empty path cannot be exercised.');
        }

        $this->assertNull(BrandLogo::dataUri());
    }
}
