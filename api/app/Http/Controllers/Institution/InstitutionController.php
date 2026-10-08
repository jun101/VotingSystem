<?php

namespace App\Http\Controllers\Institution;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Institution\UpdateInstitutionRequest;
use App\Http\Resources\InstitutionProfileResource;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InstitutionController extends Controller
{
    /**
     * The institution's profile.
     *
     * The profile of the signed-in user's own institution; there is no way to name another. An
     * owner or a manager may read it. A platform admin has no institution: 403. Signed-in user.
     *
     * @response array{data: array{id: string, name: string, type: 'school'|'university'|'association'|'other', description: string|null, address: string|null, city: string|null, phone: string|null, contact_email: string|null, timezone: string, language: 'fr'|'en', logo: array{sm: string, md: string, lg: string}|null}}
     */
    public function show(Request $request): InstitutionProfileResource
    {
        return new InstitutionProfileResource($this->institutionOf($request));
    }

    /**
     * Change the institution's profile.
     *
     * Every field is optional; only those sent change. Strings are trimmed, and an optional field
     * sent empty is cleared. The suspension and the logo cannot be reached from here. Owner only.
     *
     * @response array{data: array{id: string, name: string, type: 'school'|'university'|'association'|'other', description: string|null, address: string|null, city: string|null, phone: string|null, contact_email: string|null, timezone: string, language: 'fr'|'en', logo: array{sm: string, md: string, lg: string}|null}}
     */
    public function update(UpdateInstitutionRequest $request): InstitutionProfileResource
    {
        $institution = $this->institutionOf($request);
        $institution->fill($request->validated());
        $institution->save();

        Log::info('institution.update', ['outcome' => 'updated']);

        return new InstitutionProfileResource($institution);
    }

    private function institutionOf(Request $request): Institution
    {
        $user = $request->user();

        // A platform admin belongs to no institution.
        if (! $user instanceof User || $user->institution === null) {
            throw new ApiException(403, 'forbidden');
        }

        return $user->institution;
    }
}
