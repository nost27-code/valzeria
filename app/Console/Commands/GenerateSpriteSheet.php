<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class GenerateSpriteSheet extends Command
{
    protected $signature = 'valzeria:make-sprite-sheet
        {source? : public からの相対パス、またはプロジェクト相対/絶対パス}
        {--name= : 出力ファイル名。未指定時は入力ディレクトリ名}
        {--output=public/generated/sprites : 出力先ディレクトリ}
        {--cell=64 : セルの正方形サイズ}
        {--cell-width= : セル幅。指定時は --cell より優先}
        {--cell-height= : セル高さ。指定時は --cell より優先}
        {--columns=0 : 1行あたりの数。0なら自動}
        {--padding=2 : セル間の余白}
        {--recursive : サブディレクトリも対象にする}
        {--format=png : 出力画像形式 png または webp}
        {--extensions=png,webp,jpg,jpeg,gif : 対象拡張子}
        {--include= : 対象ファイル名パターン。カンマ区切り、例: valmon*.webp,icon_*.webp}
        {--exclude= : 除外ファイル名パターン。カンマ区切り、例: ranch_bg*.webp}
        {--force : 既存ファイルを上書きする}';

    protected $description = 'キャラ、モンスター、アイコン等の画像からスプライトシート、JSON座標、CSSクラスを生成します';

    public function handle(): int
    {
        if (!extension_loaded('gd')) {
            $this->error('GD拡張が有効ではありません。スプライトシート生成にはGDが必要です。');
            return self::FAILURE;
        }

        $source = (string) ($this->argument('source') ?: 'images/chara');
        $sourcePath = $this->resolveInputPath($source);
        if (!is_dir($sourcePath)) {
            $this->error("入力ディレクトリが見つかりません: {$sourcePath}");
            return self::FAILURE;
        }

        $sheetName = $this->safeName((string) ($this->option('name') ?: basename($sourcePath)));
        $outputDir = $this->resolveOutputPath((string) $this->option('output'));
        $format = strtolower((string) $this->option('format'));
        if (!in_array($format, ['png', 'webp'], true)) {
            $this->error('--format は png または webp を指定してください。');
            return self::FAILURE;
        }

        $cellWidth = (int) ($this->option('cell-width') ?: $this->option('cell'));
        $cellHeight = (int) ($this->option('cell-height') ?: $this->option('cell'));
        $columns = (int) $this->option('columns');
        $padding = max(0, (int) $this->option('padding'));
        if ($cellWidth <= 0 || $cellHeight <= 0) {
            $this->error('セルサイズは1以上を指定してください。');
            return self::FAILURE;
        }

        $files = $this->imageFiles($sourcePath);
        if (empty($files)) {
            $this->warn("対象画像がありません: {$sourcePath}");
            return self::SUCCESS;
        }

        if ($columns <= 0) {
            $columns = (int) ceil(sqrt(count($files)));
        }

        $rows = (int) ceil(count($files) / $columns);
        $sheetWidth = ($columns * $cellWidth) + (($columns + 1) * $padding);
        $sheetHeight = ($rows * $cellHeight) + (($rows + 1) * $padding);
        $sheet = imagecreatetruecolor($sheetWidth, $sheetHeight);
        imagealphablending($sheet, false);
        imagesavealpha($sheet, true);
        $transparent = imagecolorallocatealpha($sheet, 0, 0, 0, 127);
        imagefill($sheet, 0, 0, $transparent);

        $sprites = [];
        foreach ($files as $index => $file) {
            $sourceImage = $this->loadImage($file);
            if (!$sourceImage) {
                $this->warn('読み込みをスキップしました: ' . $this->relativePath($file));
                continue;
            }

            $originalWidth = imagesx($sourceImage);
            $originalHeight = imagesy($sourceImage);
            [$drawWidth, $drawHeight] = $this->fitSize($originalWidth, $originalHeight, $cellWidth, $cellHeight);
            $col = $index % $columns;
            $row = intdiv($index, $columns);
            $x = $padding + ($col * ($cellWidth + $padding));
            $y = $padding + ($row * ($cellHeight + $padding));
            $drawX = $x + intdiv($cellWidth - $drawWidth, 2);
            $drawY = $y + intdiv($cellHeight - $drawHeight, 2);

            imagecopyresampled($sheet, $sourceImage, $drawX, $drawY, 0, 0, $drawWidth, $drawHeight, $originalWidth, $originalHeight);
            imagedestroy($sourceImage);

            $key = $this->spriteKey($sourcePath, $file);
            $sprites[$key] = [
                'file' => $this->publicRelativePath($file),
                'x' => $x,
                'y' => $y,
                'width' => $cellWidth,
                'height' => $cellHeight,
                'draw_x' => $drawX,
                'draw_y' => $drawY,
                'draw_width' => $drawWidth,
                'draw_height' => $drawHeight,
                'original_width' => $originalWidth,
                'original_height' => $originalHeight,
            ];
        }

        File::ensureDirectoryExists($outputDir);
        $imagePath = "{$outputDir}/{$sheetName}.{$format}";
        $jsonPath = "{$outputDir}/{$sheetName}.json";
        $cssPath = "{$outputDir}/{$sheetName}.css";
        if (!$this->option('force') && (file_exists($imagePath) || file_exists($jsonPath) || file_exists($cssPath))) {
            $this->error('出力ファイルが既に存在します。上書きする場合は --force を指定してください。');
            imagedestroy($sheet);
            return self::FAILURE;
        }

        $this->saveSheet($sheet, $imagePath, $format);
        imagedestroy($sheet);

        $manifest = [
            'name' => $sheetName,
            'image' => $this->publicRelativePath($imagePath),
            'css' => $this->publicRelativePath($cssPath),
            'width' => $sheetWidth,
            'height' => $sheetHeight,
            'cell_width' => $cellWidth,
            'cell_height' => $cellHeight,
            'padding' => $padding,
            'columns' => $columns,
            'count' => count($sprites),
            'generated_at' => now()->toIso8601String(),
            'sprites' => $sprites,
        ];

        File::put($jsonPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        File::put($cssPath, $this->css($sheetName, $manifest));

        $this->info("スプライトシートを生成しました: {$this->relativePath($imagePath)}");
        $this->line("JSON: {$this->relativePath($jsonPath)}");
        $this->line("CSS : {$this->relativePath($cssPath)}");
        $this->line("画像数: " . count($sprites) . " / {$columns}列 x {$rows}行");

        return self::SUCCESS;
    }

    private function resolveInputPath(string $path): string
    {
        $candidates = [
            $path,
            base_path($path),
            public_path($path),
            public_path('images/' . ltrim($path, '/\\')),
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        return public_path($path);
    }

    private function resolveOutputPath(string $path): string
    {
        if (str_starts_with($path, base_path()) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
            return rtrim(str_replace('\\', '/', $path), '/');
        }

        return rtrim(str_replace('\\', '/', base_path($path)), '/');
    }

    private function imageFiles(string $sourcePath): array
    {
        $extensions = collect(explode(',', (string) $this->option('extensions')))
            ->map(fn (string $value) => strtolower(trim($value)))
            ->filter()
            ->all();
        $files = $this->option('recursive') ? File::allFiles($sourcePath) : File::files($sourcePath);

        return collect($files)
            ->filter(fn ($file) => in_array(strtolower($file->getExtension()), $extensions, true))
            ->filter(fn ($file) => $this->matchesPatterns($file->getFilename(), (string) $this->option('include'), true))
            ->reject(fn ($file) => $this->matchesPatterns($file->getFilename(), (string) $this->option('exclude'), false))
            ->map(fn ($file) => $file->getPathname())
            ->sort(SORT_NATURAL)
            ->values()
            ->all();
    }

    private function matchesPatterns(string $filename, string $patterns, bool $default): bool
    {
        $patterns = collect(explode(',', $patterns))
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->all();

        if (empty($patterns)) {
            return $default;
        }

        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $filename, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    private function loadImage(string $path): mixed
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => @imagecreatefrompng($path),
            'webp' => @imagecreatefromwebp($path),
            'jpg', 'jpeg' => @imagecreatefromjpeg($path),
            'gif' => @imagecreatefromgif($path),
            default => false,
        };
    }

    private function saveSheet(mixed $sheet, string $path, string $format): void
    {
        $saved = match ($format) {
            'webp' => imagewebp($sheet, $path, 90),
            default => imagepng($sheet, $path, 9),
        };

        if (!$saved) {
            throw new RuntimeException("スプライトシートを保存できませんでした: {$path}");
        }
    }

    private function fitSize(int $width, int $height, int $maxWidth, int $maxHeight): array
    {
        $scale = min($maxWidth / max(1, $width), $maxHeight / max(1, $height), 1);

        return [
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale)),
        ];
    }

    private function spriteKey(string $sourcePath, string $file): string
    {
        $relative = Str::of($file)
            ->replace('\\', '/')
            ->after(rtrim(str_replace('\\', '/', $sourcePath), '/') . '/')
            ->beforeLast('.');

        return $this->safeName((string) $relative);
    }

    private function safeName(string $value): string
    {
        $value = Str::of($value)
            ->replace('\\', '/')
            ->replace('/', '-')
            ->replaceMatches('/[^A-Za-z0-9_.-]+/', '-')
            ->trim('-');

        return (string) ($value->isEmpty() ? 'sprites' : $value);
    }

    private function css(string $sheetName, array $manifest): string
    {
        $image = '/' . ltrim((string) $manifest['image'], '/');
        $baseClass = ".sprite-{$sheetName}";
        $lines = [
            "/* Generated by php artisan valzeria:make-sprite-sheet. */",
            "{$baseClass} {",
            "    display: inline-block;",
            "    width: {$manifest['cell_width']}px;",
            "    height: {$manifest['cell_height']}px;",
            "    background-image: url('{$image}');",
            "    background-repeat: no-repeat;",
            "    background-size: {$manifest['width']}px {$manifest['height']}px;",
            "}",
            '',
        ];

        foreach ($manifest['sprites'] as $key => $sprite) {
            $lines[] = "{$baseClass}.sprite-{$key} { background-position: -{$sprite['x']}px -{$sprite['y']}px; }";
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function publicRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', realpath($path) ?: $path);
        $public = str_replace('\\', '/', realpath(public_path()) ?: public_path());

        if (str_starts_with($path, $public . '/')) {
            return ltrim(substr($path, strlen($public)), '/');
        }

        return $this->relativePath($path);
    }

    private function relativePath(string $path): string
    {
        $path = str_replace('\\', '/', realpath($path) ?: $path);
        $base = str_replace('\\', '/', realpath(base_path()) ?: base_path());

        return str_starts_with($path, $base . '/') ? substr($path, strlen($base) + 1) : $path;
    }
}
