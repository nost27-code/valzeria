<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BugReport extends Model
{
    public const KIND_BUG = 'bug';

    public const KIND_SUGGESTION = 'suggestion';

    public const KINDS = [
        self::KIND_BUG,
        self::KIND_SUGGESTION,
    ];

    protected $guarded = [];

    protected $casts = [
        'read_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(BugReportAttachment::class);
    }

    public function isSuggestion(): bool
    {
        return $this->kind === self::KIND_SUGGESTION;
    }

    public function kindLabel(): string
    {
        return $this->isSuggestion() ? '改善の要望' : '不具合報告';
    }
}
