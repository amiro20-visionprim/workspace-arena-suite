<?php

declare(strict_types=1);

namespace App\Domains\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P2.5 — هر آیتم از دسته تولید گروهی (یک کیوورد → یک پیش‌نویس).
 */
class BulkJobItem extends Model
{
    protected $fillable = [
        'bulk_job_id',
        'keyword',
        'title',
        'status',
        'source',
        'draft_id',
        'quality_score',
        'word_count',
        'slug',
        'error',
        'started_at',
        'finished_at',
        // P2.6 — ستون‌های انتشار
        'publish_status',
        'post_id',
        'published_at',
        'publish_error',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public const PUBLISH_PENDING = 'pending';
    public const PUBLISH_PUBLISHING = 'publishing';
    public const PUBLISH_PUBLISHED = 'published';
    public const PUBLISH_FAILED = 'failed';

    public function job(): BelongsTo
    {
        return $this->belongsTo(BulkJob::class, 'bulk_job_id');
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ContentDraft::class, 'draft_id');
    }
}