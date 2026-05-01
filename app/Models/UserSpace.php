<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserSpaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class UserSpace extends Model
{
    /** @use HasFactory<UserSpaceFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
    ];

    protected static function booted(): void
    {
        static::creating(function (UserSpace $userSpace): void {
            if (! $userSpace->uuid) {
                $userSpace->uuid = (string) Str::uuid();
            }
        });
    }

    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    public function tokenUsageLogs(): HasMany
    {
        return $this->hasMany(TokenUsageLog::class);
    }

    public function workDir(): string
    {
        $basePath = rtrim((string) config('hipporag.work_dir_prefix'), '/');

        return $basePath.'/userspace_'.$this->uuid;
    }

    public function storageDirectory(): string
    {
        return 'userspaces/'.$this->uuid;
    }
}
