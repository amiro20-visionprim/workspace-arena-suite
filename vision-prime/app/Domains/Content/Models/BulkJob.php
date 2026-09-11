<?php

declare(strict_types=1);

namespace App\Domains\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P2.5 — دسته تولید گروهی محتوا.
 */
class BulkJob extends Model
{
    protected $fillable = [
        'organization_id',
        'site_id',
        'name',
        'content_type',
        'subtype',
        'auto_publish', // off|draft|publish (P2.7)
        'scheduled_at', // P2.8 — زمان شروع پردازش
        'daily_publish_limit', // P2.8 — سقف انتشار روزانه
        'status',
        'total_items',
        'completed_items',
        'failed_items',
        'needs_review_items',
        'created_by',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'scheduled_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(BulkJobItem::class);
    }

    public function progressPercent(): int
    {
        if ($this->total_items <= 0) {
            return 0;
        }

        // needs_review هم «پردازش‌شده» است (خروجی نیازمند بازبینی انسانی)
        return (int) round(($this->completed_items + $this->failed_items + $this->needs_review_items) / $this->total_items * 100);
    }
}