<?php

namespace App\Http\Requests\Elections;

use App\Rules\IsoDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * docs/api/elections/POST-elections-{election}-extend.md: `ends_at` is required and an ISO 8601 date and time.
 * Its place against the current end is judged in the controller, under the row lock.
 */
class ExtendElectionRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'ends_at' => ['bail', 'required', new IsoDateTime],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        /** @var array<string, array<string, mixed>> $failed */
        $failed = $validator->failed();

        throw ValidationException::withMessages([
            'ends_at' => [array_key_exists('Required', $failed['ends_at'] ?? []) ? 'required' : 'invalid'],
        ]);
    }

    public function newEnd(): CarbonImmutable
    {
        $end = IsoDateTime::parse($this->input('ends_at'));
        assert($end !== null);

        return $end;
    }
}
