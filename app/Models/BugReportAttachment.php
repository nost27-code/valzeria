<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BugReportAttachment extends Model
{
    protected $guarded = [];

    public function bugReport(): BelongsTo
    {
        return $this->belongsTo(BugReport::class);
    }
}
