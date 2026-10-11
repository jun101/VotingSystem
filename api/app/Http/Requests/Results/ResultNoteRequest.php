<?php

namespace App\Http\Requests\Results;

use Illuminate\Foundation\Http\FormRequest;

/**
 * docs/api/results/PUT-ballots-{ballot}-result-note.md: `note` is a string or null. It is trimmed, its
 * control characters other than line breaks are removed, and empty clears it; at most 1 000 characters.
 */
class ResultNoteRequest extends FormRequest
{
    /** The longest note. */
    private const MAX = 1000;

    protected function prepareForValidation(): void
    {
        $value = $this->input('note');

        if (is_string($value)) {
            // Control characters go (a line break and a carriage return stay as line breaks), then the ends are trimmed.
            $clean = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace("\r\n", "\n", $value));
            $clean = trim($clean ?? '');
            $this->merge(['note' => $clean === '' ? null : $clean]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:'.self::MAX],
        ];
    }

    /** The note to store, or null to clear. */
    public function note(): ?string
    {
        $note = $this->input('note');

        return is_string($note) ? $note : null;
    }
}
