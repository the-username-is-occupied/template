<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SourceDraftStatus;
use App\Enums\SourceType;
use Database\Factories\SourceDraftFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceDraft extends Model
{
    /** @use HasFactory<SourceDraftFactory> */
    use HasFactory;
    use HasUuids;

    protected $table = 'source_drafts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'knowledge_base_id',
        'content_source_id',
        'type',
        'raw_input',
        'channel_meta',
        'scrape_config',
        'auto_update',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'knowledge_base_id' => 'string',
            'content_source_id' => 'string',
            'type' => SourceType::class,
            'auto_update' => 'boolean',
            'channel_meta' => 'array',
            'scrape_config' => 'array',
            'status' => SourceDraftStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(Notebook::class, 'knowledge_base_id');
    }

    public function contentSource(): BelongsTo
    {
        return $this->belongsTo(ContentSource::class);
    }
}
