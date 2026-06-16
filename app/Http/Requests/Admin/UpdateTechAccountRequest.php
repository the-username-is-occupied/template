<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\TechAccountPoolType;
use App\Enums\TechAccountStatus;
use App\Models\TechAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTechAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, Rule|string>>
     */
    public function rules(): array
    {
        $account = $this->route('techAccount');
        $accountId = $account instanceof TechAccount ? $account->getKey() : $account;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('tech_accounts', 'email')->ignore($accountId)],
            'pool_type' => ['required', Rule::enum(TechAccountPoolType::class)],
            'status' => ['required', Rule::enum(TechAccountStatus::class)],
            'notebooks_count' => ['nullable', 'integer', 'min:0'],
            'chats_today' => ['nullable', 'integer', 'min:0'],
            'chats_reset_at' => ['nullable', 'date'],
            'last_used_at' => ['nullable', 'date'],
            'storage_state' => ['nullable', 'file'],
        ];
    }
}
