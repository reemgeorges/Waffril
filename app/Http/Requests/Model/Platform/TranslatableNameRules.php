<?php

namespace App\Http\Requests\Model\Platform;

/**
 * WAFFRLI P0 (2026-10-30) — shared rules for an Arabic + English
 * translatable `name` field ({"ar": "...", "en": "..."}).
 */
trait TranslatableNameRules
{
    protected function nameRules(bool $required = true): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'array:ar,en'],
            'name.ar' => ['required_with:name', 'string', 'max:150'],
            'name.en' => ['required_with:name', 'string', 'max:150'],
        ];
    }
}
