<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\UserSpace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

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
            'files' => ['nullable', 'array'],
            'files.*' => ['required', File::types(['txt', 'md', 'text'])->max('10mb')],
            'pasted_text' => ['nullable', 'string', 'max:200000'],
            'llm_model_name' => ['required', 'string', 'max:190'],
            'index_mode' => ['required', 'in:index,chunk'],
            'chunk_size' => ['required', 'integer', 'min:64', 'max:4096'],
            'overlap_ratio' => ['required', 'numeric', 'min:0.1', 'max:0.15'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'files.array' => 'Files payload is invalid.',
            'files.*.mimes' => 'Only .txt, .text, and .md files are supported for this POC.',
            'llm_model_name.required' => 'Please choose an LLM model.',
            'index_mode.in' => 'Unsupported indexing mode.',
            'overlap_ratio.min' => 'Overlap ratio must be at least 0.10 (10%).',
            'overlap_ratio.max' => 'Overlap ratio must be at most 0.15 (15%).',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $files = $this->file('files', []);
            $pastedText = trim((string) $this->input('pasted_text', ''));

            if (count($files) === 0 && $pastedText === '') {
                $validator->errors()->add('files', 'Upload at least one file or paste text.');
            }
        });
    }

    public function userSpace(): UserSpace
    {
        return UserSpace::query()->findOrFail($this->integer('user_space_id'));
    }

    public function llmModelName(): string
    {
        return (string) $this->validated('llm_model_name');
    }

    public function indexMode(): string
    {
        return (string) $this->validated('index_mode');
    }

    public function chunkSize(): int
    {
        return (int) $this->validated('chunk_size');
    }

    public function overlapRatio(): float
    {
        return (float) $this->validated('overlap_ratio');
    }

    public function pastedText(): string
    {
        return trim((string) $this->validated('pasted_text', ''));
    }
}
