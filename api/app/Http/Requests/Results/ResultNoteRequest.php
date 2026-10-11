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
            // A carriage return becomes a line break; every control character, the bidirectional controls and the
            // zero-width characters go (a note is shown on a public page, where they could reverse or hide text);
            // then the ends are trimmed.
            $text = str_replace(["\r\n", "\r"], "\n", $value);
            $clean = preg_replace('/(?!\n)\p{Cc}|[\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u', '', $text);
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
