<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the installable-app setup. The failure these cover is a quiet one:
 * a manifest that promises icons nobody generated still parses fine, and the
 * install prompt simply never appears.
 */
class PwaTest extends TestCase
{
    /** @return array<string, mixed> */
    protected function manifest(): array
    {
        $path = public_path('manifest.webmanifest');

        $this->assertFileExists($path);

        $manifest = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($manifest, 'The manifest is not valid JSON.');

        return $manifest;
    }

    public function test_the_manifest_declares_what_an_install_needs(): void
    {
        $manifest = $this->manifest();

        foreach (['name', 'short_name', 'start_url', 'scope', 'display', 'theme_color', 'background_color', 'icons'] as $key) {
            $this->assertArrayHasKey($key, $manifest, "The manifest is missing `{$key}`.");
        }

        $this->assertSame('standalone', $manifest['display']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $manifest['theme_color']);
    }

    public function test_every_icon_the_manifest_promises_exists_at_the_size_it_claims(): void
    {
        foreach ($this->manifest()['icons'] as $icon) {
            $path = public_path(ltrim($icon['src'], '/'));

            $this->assertFileExists($path, "Manifest references a missing icon: {$icon['src']}");

            [$width, $height] = getimagesize($path);
            [$declaredWidth, $declaredHeight] = array_map('intval', explode('x', $icon['sizes']));

            $this->assertSame($declaredWidth, $width, "{$icon['src']} is {$width}px wide, not {$declaredWidth}px.");
            $this->assertSame($declaredHeight, $height, "{$icon['src']} is {$height}px tall, not {$declaredHeight}px.");
        }
    }

    public function test_android_gets_both_a_plain_and_a_maskable_icon_at_the_required_sizes(): void
    {
        $icons = $this->manifest()['icons'];

        $purposes = [];

        foreach ($icons as $icon) {
            $purposes[$icon['purpose'] ?? 'any'][] = $icon['sizes'];
        }

        // Chrome refuses to prompt without a 192 and a 512.
        $this->assertContains('192x192', $purposes['any']);
        $this->assertContains('512x512', $purposes['any']);

        // Without a maskable set, Android pillarboxes the icon in a white blob.
        $this->assertArrayHasKey('maskable', $purposes);
        $this->assertContains('512x512', $purposes['maskable']);
    }

    public function test_the_shell_files_are_in_place(): void
    {
        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileExists(public_path('offline.html'));
        $this->assertFileExists(public_path('icons/apple-touch-icon.png'));
        $this->assertFileExists(public_path('favicon.ico'));

        // iOS sizes it itself, but a non-square source gets squashed.
        [$width, $height] = getimagesize(public_path('icons/apple-touch-icon.png'));
        $this->assertSame(180, $width);
        $this->assertSame(180, $height);
    }

    /**
     * iOS and Android both composite these onto their own background, and
     * neither honours transparency: a transparent corner renders as solid
     * black. The generator flattens them for exactly this reason, so this is
     * the regression guard for that step.
     */
    public function test_the_icons_that_cannot_carry_transparency_are_opaque(): void
    {
        $mustBeOpaque = [
            'icons/apple-touch-icon.png',
            'icons/apple-touch-icon-152.png',
            'icons/apple-touch-icon-167.png',
            'icons/apple-touch-icon-180.png',
            'icons/maskable-192.png',
            'icons/maskable-512.png',
        ];

        foreach ($mustBeOpaque as $relative) {
            $path = public_path($relative);

            $this->assertFileExists($path);

            $image = imagecreatefrompng($path);
            $this->assertNotFalse($image, "Could not read {$relative}.");

            [$width, $height] = [imagesx($image), imagesy($image)];

            // The corners are where a rounded source leaves transparency behind.
            foreach ([[0, 0], [$width - 1, 0], [0, $height - 1], [$width - 1, $height - 1]] as [$x, $y]) {
                // GD stores alpha 0-127, where 0 is fully opaque.
                $alpha = (imagecolorat($image, $x, $y) >> 24) & 0x7F;

                $this->assertSame(0, $alpha, "{$relative} is transparent at ({$x}, {$y}); it will render black.");
            }

            imagedestroy($image);
        }
    }

    public function test_the_service_worker_leaves_livewire_and_the_api_alone(): void
    {
        $worker = (string) file_get_contents(public_path('sw.js'));

        // Caching or replaying a Livewire update would corrupt component state.
        $this->assertStringContainsString("request.method !== 'GET'", $worker);
        $this->assertStringContainsString('/livewire', $worker);
        $this->assertStringContainsString('/api', $worker);
    }

    public function test_every_page_advertises_the_app(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('rel="manifest"', escape: false)
            ->assertSee('apple-touch-icon', escape: false)
            ->assertSee('name="theme-color"', escape: false);
    }
}
