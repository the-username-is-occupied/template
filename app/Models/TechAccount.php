<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TechAccount extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'tech_accounts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'email',
        'pool_type',
        'status',
        'cookie_path',
        'notebooks_count',
        'chats_today',
        'chats_reset_at',
        'last_used_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'chats_reset_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function notebooks()
    {
        return $this->hasMany(TechNotebook::class, 'account_id');
    }
}
