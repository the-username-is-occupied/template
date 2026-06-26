<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TgUserSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TgUserSession extends Model
{
    /** @use HasFactory<TgUserSessionFactory> */
    use HasFactory;

    protected $primaryKey = 'tg_user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'tg_user_id',
        'active_base_id',
    ];

    protected function casts(): array
    {
        return [
            'tg_user_id' => 'integer',
            'active_base_id' => 'string',
        ];
    }

    public function activeBase(): BelongsTo
    {
        return $this->belongsTo(Notebook::class, 'active_base_id');
    }
}
