<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Agents\AskPreprocessor as Agent;
use App\Data\PreprocessData;
use App\Enums\PreprocessStatus;
use App\Models\Chat;
use App\Models\Notebook;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AskPreprocessor
{
    public function preprocess(User $user, Notebook $notebook, string $question, bool $isNew = false): Chat|PreprocessData
    {

        $str = Str::squish($question);
        if ($str === '' || mb_strlen($str) < 2) {

            return PreprocessData::from([

                'decision' => PreprocessStatus::INSUFFICIENT,
                'reason' => 'Неясное сообщение',
                'suggested_short_reply' => 'Пожалуйста, уточните, что именно вас интересует или задайте вопрос!',
            ]);
        }

        $chat = $isNew ? null : Chat::query()->user($user)->notebook($notebook)->latest()->first();

        $agent = new Agent($chat);
        $dto = PreprocessData::from($agent->prompt($question)->toArray());

        Log::info('Preprocess data:', $dto->toArray());

        if ($dto->decision === PreprocessStatus::INSUFFICIENT) {
            return $dto;
        }

        if (! $isNew && $chat && $chat->notebook_id === $notebook->id) {
            return $chat;
        }

        return Chat::create([
            'user_id' => $user->id,
            'notebook_id' => $notebook->id,
        ]);
    }
}
