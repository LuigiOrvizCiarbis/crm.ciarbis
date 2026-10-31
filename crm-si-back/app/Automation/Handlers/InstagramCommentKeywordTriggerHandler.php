<?php

namespace App\Automation\Handlers;

use App\Automation\Contracts\TriggerHandler;
use App\Models\AutomationRule;
use Illuminate\Support\Str;

class InstagramCommentKeywordTriggerHandler implements TriggerHandler
{
    public function type(): string
    {
        return 'instagram.comment_keyword';
    }

    public function metadata(): array
    {
        return [
            'label' => 'Palabra clave en comentario de Instagram',
            'subject' => 'instagram_comment',
            'kind' => 'event',
            'config_fields' => ['channel_id', 'keywords'],
        ];
    }

    public function validate(array $config): array
    {
        $errors = [];

        if (! filter_var($config['channel_id'] ?? null, FILTER_VALIDATE_INT)) {
            $errors['channel_id'][] = 'Seleccioná un canal de Instagram.';
        }

        $keywords = $config['keywords'] ?? null;
        if (! is_array($keywords) || $keywords === []) {
            $errors['keywords'][] = 'Agregá al menos una palabra clave.';
        } elseif (count($keywords) > 20) {
            $errors['keywords'][] = 'Podés agregar hasta 20 palabras clave.';
        } else {
            foreach ($keywords as $index => $keyword) {
                if (! is_string($keyword) || trim($keyword) === '' || mb_strlen(trim($keyword)) > 60) {
                    $errors["keywords.{$index}"][] = 'Cada palabra clave debe tener entre 1 y 60 caracteres.';
                }
            }
        }

        return $errors;
    }

    public function matches(AutomationRule $rule, array $event): bool
    {
        if (($event['type'] ?? null) !== $this->type()
            || ($event['subject_type'] ?? null) !== 'instagram_comment'
            || (int) ($event['channel_id'] ?? 0) !== (int) ($rule->trigger_config['channel_id'] ?? 0)) {
            return false;
        }

        $commentTokens = $this->tokens((string) ($event['text'] ?? ''));
        if ($commentTokens === []) {
            return false;
        }

        foreach ($rule->trigger_config['keywords'] ?? [] as $keyword) {
            $keywordTokens = $this->tokens((string) $keyword);
            if ($keywordTokens !== [] && $this->containsSequence($commentTokens, $keywordTokens)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function tokens(string $value): array
    {
        $normalized = Str::lower(Str::ascii($value));

        return array_values(array_filter(preg_split('/[^a-z0-9]+/', $normalized) ?: []));
    }

    /** @param list<string> $haystack @param list<string> $needle */
    private function containsSequence(array $haystack, array $needle): bool
    {
        $length = count($needle);
        for ($index = 0; $index <= count($haystack) - $length; $index++) {
            if (array_slice($haystack, $index, $length) === $needle) {
                return true;
            }
        }

        return false;
    }
}
