<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class NotebookContentSource extends Pivot
{
    protected $table = 'notebook_content_sources';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = null;

    public $timestamps = false;

    protected $fillable = [
        'notebook_id',
        'content_source_id',
        'added_at',
    ];

    protected function casts(): array
    {
        return [
            'added_at' => 'datetime',
        ];
    }
}
