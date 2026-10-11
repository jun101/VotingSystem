<?php

namespace App\Http\Requests\Credentials;

use Illuminate\Contracts\Validation\Validator;

/** docs/api/credentials/POST-elections-{election}-credential-runs.md, with the channels of slice 11. */
class StartCredentialRunRequest extends CredentialScopeRequest
{
    /**
     * `email` is a boolean, `confirm_emails` an integer of 0 or more (required with `email`), `file`
     * `xlsx`, `csv` or null. The codes reported are those of the endpoint file, see `failedValidation`.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'email' => ['bail', 'nullable', 'boolean'],
            'confirm_emails' => ['bail', 'nullable', 'integer', 'min:0'],
            'file' => ['bail', 'nullable', 'string', 'in:xlsx,csv'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return array_merge(parent::after(), [function (Validator $validator): void {
            $errors = $validator->errors();

            // `confirm_emails` is needed when emails are sent, so the person confirms a count.
            if ($this->wantsEmail() && ! $errors->has('email')) {
                $confirm = $this->input('confirm_emails');

                if (($confirm === null || $confirm === '') && ! $errors->has('confirm_emails')) {
                    $errors->add('confirm_emails', 'required');
                }
            }

            // A new code for a failed message is sent by email or not at all.
            if ($this->input('scope') === 'failed_email' && ! $this->wantsEmail() && ! $errors->has('email')) {
                $errors->add('email', 'required');
            }
        }]);
    }

    /** Whether the voters served are emailed their code. */
    public function wantsEmail(): bool
    {
        return $this->boolean('email');
    }

    /** The count of emails the person confirmed, or null. */
    public function confirmEmails(): ?int
    {
        $confirm = $this->input('confirm_emails');

        return is_numeric($confirm) ? (int) $confirm : null;
    }

    /** `xlsx`, `csv` or null: the self-distribution file asked for. */
    public function fileFormat(): ?string
    {
        $file = $this->input('file');

        return $file === 'xlsx' || $file === 'csv' ? $file : null;
    }
}
