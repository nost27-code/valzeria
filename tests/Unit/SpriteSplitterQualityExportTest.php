<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SpriteSplitterQualityExportTest extends TestCase
{
    public function test_sprite_splitter_exposes_high_quality_and_lossless_modes(): void
    {
        $html = file_get_contents($this->publicPath('tools/sprite-splitter.html'));

        $this->assertIsString($html);
        $this->assertStringContainsString('<option value="128">128×128</option>', $html);
        $this->assertStringContainsString('name="resize-mode" value="high-quality" checked', $html);
        $this->assertStringContainsString("imageSmoothingQuality = 'high'", $html);
        $this->assertStringContainsString('name="webp-mode" value="lossless" checked', $html);
        $this->assertStringContainsString("import('./vendor/jsquash-webp/encode.js')", $html);
        $this->assertStringContainsString('lossless: 1', $html);
        $this->assertStringContainsString("signature.includes('VP8L')", $html);
    }

    public function test_lossless_webp_encoder_assets_are_bundled(): void
    {
        foreach ([
            'encode.js',
            'meta.js',
            'utils.js',
            'wasm-feature-detect.js',
            'README.md',
            'codec/enc/webp_enc.js',
            'codec/enc/webp_enc.wasm',
            'codec/enc/webp_enc_simd.js',
            'codec/enc/webp_enc_simd.wasm',
            'LICENSE',
            'codec/LICENSE.codec.md',
        ] as $path) {
            $this->assertFileExists($this->publicPath('tools/vendor/jsquash-webp/'.$path));
        }
    }

    private function publicPath(string $path): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
