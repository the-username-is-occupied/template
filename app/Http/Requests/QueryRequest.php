<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\UserSpace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QueryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_space_id' => ['required', 'integer', 'exists:user_spaces,id'],
            'questions' => ['required', 'string', 'max:20000'],
            'mode' => ['required', Rule::in(['rag', 'retrieve'])],
            'num_to_retrieve' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * @return list<string>
     */
    public function queries(): array
    {
        $questions = (string) $this->validated('questions');

        return array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $questions) ?: []),
            static fn (string $line): bool => $line !== ''
        ));
    }

    public function userSpace(): UserSpace
    {
        return UserSpace::query()->findOrFail((int) $this->validated('user_space_id'));
    }
}
