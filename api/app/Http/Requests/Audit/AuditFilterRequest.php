<?php

namespace App\Http\Requests\Audit;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The filters shared by docs/api/audit/GET-audit.md and GET-audit-export.md: `action`, `election`, `user`, `from`
 * and `to`. A value that cannot match anything (an unknown action, a uuid that is not one) is not an error: the
 * answer is an empty list. Only the two dates are validated.
 */
abstract class AuditFilterRequest extends FormRequest
{
    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            // Plain `nullable` only: a long or odd value is not an error. A filter that cannot match gives an empty
            // list; the dates report `invalid` in `after()`, whatever they hold.
            'action' => ['nullable'],
            'election' => ['nullable'],
            'user' => ['nullable'],
            'from' => ['nullable'],
            'to' => ['nullable'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['from', 'to'] as $field) {
                $value = $this->input($field);

                if ($value === null || $value === '') {
                    continue;
                }

                if (! is_string($value) || $this->day($value) === null) {
                    $validator->errors()->add($field, 'invalid');
                }
            }
        }];
    }

    /** An action code, or a family prefix ending with a dot; null when not asked. */
    public function action(): ?string
    {
        return $this->text('action');
    }

    public function election(): ?string
    {
        return $this->text('election');
    }

    /** A user's uuid, or `system`; null when not asked. */
    public function actor(): ?string
    {
        return $this->text('user');
    }

    /** The first day asked for, `YYYY-MM-DD`, or null. */
    public function from(): ?string
    {
        return $this->day($this->text('from'));
    }

    /** The last day asked for, `YYYY-MM-DD`, or null. */
    public function to(): ?string
    {
        return $this->day($this->text('to'));
    }

    private function text(string $key): ?string
    {
        $value = $this->input($key);

        if ($value === null) {
            return null;
        }

        // An array or a byte string that is not text cannot name anything: it matches nothing (never an error).
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return '?';
        }

        return trim($value) !== '' ? trim($value) : null;
    }

    /** The date when it is a real `YYYY-MM-DD` day (2026-13-40 is not), else null. */
    private function day(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $value;
    }
}
