<?php

namespace App\Services;

use App\Models\Area;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class DungeonPublicationService
{
    public function isPublished(Area $area): bool
    {
        return !Schema::hasColumn('areas', 'is_published') || (bool) $area->is_published;
    }

    public function publishedAreas(): Builder
    {
        $query = Area::query();

        if (Schema::hasColumn('areas', 'is_published')) {
            $query->where('is_published', true);
        }

        return $query;
    }
}
