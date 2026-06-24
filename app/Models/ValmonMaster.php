<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ValmonMaster extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_starter' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function spawnRegions()
    {
        return $this->hasMany(ValmonSpawnRegion::class);
    }

    public function imageUrl(): ?string
    {
        return self::versionedAsset($this->image_path);
    }

    public static function versionedAsset(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $relativePath = ltrim($path, '/');
        $absolutePath = public_path($relativePath);
        $version = is_file($absolutePath) ? filemtime($absolutePath) : '1';

        return asset($relativePath) . '?v=' . $version;
    }
}
