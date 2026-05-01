<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\UserSpace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class IndexRequest extends FormRequest
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
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', File::types(['txt', 'md', 'text'])->max('10mb')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'files.required' => 'Please choose at least one plain text or Markdown file.',
            'files.*.mimes' => 'Only .txt, .text, and .md files are supported for this POC.',
        ];
    }

    public function userSpace(): UserSpace
    {
        return UserSpace::query()->findOrFail($this->integer('user_space_id'));
    }
}
