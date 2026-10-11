<?php

namespace App\Http\Requests\Publication;

use App\Exceptions\ApiException;
use App\Models\Election;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * docs/api/publication/POST-elections-{election}-publish.md: the optional display choice. A plain `in` rule on
 * purpose: the shared renderer answers it as `results_display: invalid`.
 */
class PublishElectionRequest extends FormRequest
{
    /**
     * Owners and managers of the election's institution may (the `update` ability, as the controller's own
     * check); anyone else answers 403 before the body is validated, so a user who may not publish never learns
     * anything from a 422.
     */
    public function authorize(): bool
    {
        $election = $this->route('election');

        if ($election instanceof Election && ! Gate::forUser($this->user())->allows('update', $election)) {
            throw new ApiException(403, 'forbidden');
        }

        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'results_display' => ['nullable', 'in:full,winners'],
        ];
    }

    /** The display asked for, or null to keep the election's own. */
    public function display(): ?string
    {
        $value = $this->input('results_display');

        return is_string($value) ? $value : null;
    }
}
