<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountTierLimit extends Model
{
    protected $table = 'account_tier_limits';

    protected $primaryKey = 'tier';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tier',
        'notebooks_limit',
        'sources_per_notebook',
        'chats_per_day',
        'audio_per_day',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'tier' => 'string',
            'notebooks_limit' => 'integer',
            'sources_per_notebook' => 'integer',
            'chats_per_day' => 'integer',
            'audio_per_day' => 'integer',
            'updated_at' => 'datetime',
        ];
    }
}
