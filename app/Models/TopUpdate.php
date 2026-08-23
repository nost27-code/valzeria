<?php

namespace App\Models;

use App\Support\PlayerStatLabel;
use Illuminate\Database\Eloquent\Model;

class TopUpdate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'published_on' => 'date',
        'is_active' => 'boolean',
        'is_dismissed' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function getBodyAttribute(mixed $value): string
    {
        return PlayerStatLabel::inText((string) $value);
    }

    public function getDetailAttribute(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return PlayerStatLabel::inText((string) $value);
    }
}
