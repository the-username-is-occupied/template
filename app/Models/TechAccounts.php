<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\NotebookLM\NotebookLMService;
use App\Enums\TechAccountPoolType;
use App\Enums\TechAccountStatus;
use Database\Factories\TechAccountsFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechAccounts extends Model
{
    /** @use HasFactory<TechAccountsFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'tech_accounts';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
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
    ];

    protected function casts(): array
    {
        return [
            'pool_type' => TechAccountPoolType::class,
            'status' => TechAccountStatus::class,
            'notebooks_count' => 'integer',
            'chats_today' => 'integer',
            'chats_reset_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function tierLimit(): BelongsTo
    {
        return $this->belongsTo(AccountTierLimit::class, 'pool_type', 'tier');
    }

    public function test(string $q = 'Кто такая Сильвана? Ответь коротко')
    {
        // sylvanas 664040dd-5608-490a-b35f-92b06979f768
        // return (new NotebookLMService)->listNotebooks($this->id);
        // return (new NotebookLMService)->getNotebook($this->id, "80be8d97-a00f-4ce1-a560-a424e65ee56d");
        // return (new NotebookLMService)->setPublic($this->id, "80be8d97-a00f-4ce1-a560-a424e65ee56d");
        return (new NotebookLMService)->askQuestion($this->id, '80be8d97-a00f-4ce1-a560-a424e65ee56d', $q);
    }

    public function createnb(string $q = 'Notebook')
    {
        return (new NotebookLMService)->createNotebook($this->id, $q);
    }
}
