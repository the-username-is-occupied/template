<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    protected $fillable = [
        'user_space_id',
        'uuid',
        'filename',
        'path',
        'size',
        'sha256',
        'original_name',
        'mime_type',
    ];

    protected static function booted(): void
    {
        static::creating(function (Source $source): void {
            $source->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<UserSpace, $this>
     */
    public function userSpace(): BelongsTo
    {
        return $this->belongsTo(UserSpace::class);
    }

    protected function storagePath(): Attribute
    {
        return Attribute::make(
            get: fn (): string => (string) $this->path,
        );
    }
}
