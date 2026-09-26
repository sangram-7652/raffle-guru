<?php

namespace App\Filament\Resources\Competitions\Pages\Concerns;

/**
 * Brings the form's dehydrated skill_question back to the exact shape
 * the rest of the system uses ({prompt, options: list<string>,
 * answer_index: int}) — see CompetitionFactory and
 * App\Http\Resources\CompetitionResource.
 *
 * `skill_question` is nullable specifically to represent a genuine
 * free-entry competition. A shaped-but-empty array (what the form's
 * dot-notation fields dehydrate to when every field is left blank) is
 * truthy, so the public API would report a blank skill question; that
 * case collapses back to a real `null` here. Validation in
 * CompetitionForm already guarantees that a filled prompt comes with
 * 2+ options and an in-range answer.
 */
trait NormalizesSkillQuestion
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeSkillQuestion(array $data): array
    {
        if (! array_key_exists('skill_question', $data)) {
            return $data;
        }

        $skillQuestion = $data['skill_question'] ?? [];
        $prompt = $skillQuestion['prompt'] ?? null;
        $options = array_values(array_filter(
            $skillQuestion['options'] ?? [],
            fn ($option) => is_string($option) && filled($option),
        ));

        if (blank($prompt) && $options === []) {
            $data['skill_question'] = null;

            return $data;
        }

        $answerIndex = $skillQuestion['answer_index'] ?? null;

        $data['skill_question'] = [
            'prompt' => $prompt,
            'options' => $options,
            'answer_index' => filled($answerIndex) ? (int) $answerIndex : null,
        ];

        return $data;
    }
}
