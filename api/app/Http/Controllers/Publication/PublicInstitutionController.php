<?php

namespace App\Http\Controllers\Publication;

use App\Enums\ElectionStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\Institution;
use App\Support\Media\ImageReEncoder;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class PublicInstitutionController extends Controller
{
    /** The most elections listed. */
    private const LIMIT = 50;

    /**
     * The public profile of an institution.
     *
     * No sign-in, no cookie. Its name, type, logo, description and city, and its `published` elections (at most
     * 50, newest publication first). No address, phone, email, user, voter or count. An unknown institution, a
     * value that is not a UUID and a suspended institution answer the same 404. Limited to 120 requests per
     * minute per address. `Cache-Control: no-cache`.
     */
    #[Response(status: 200, type: "array{data: array{id: string, name: string, type: 'school'|'university'|'association'|'other', logo: array{sm: string, md: string, lg: string}|null, description: string|null, city: string|null, elections: list<array{id: string, title: string, published_at: string|null, starts_at: string, ends_at: string, timezone: string, language: 'fr'|'en'}>}}")]
    public function __invoke(string $institution): JsonResponse
    {
        if (! Str::isUuid($institution)) {
            throw new ApiException(404, 'not_found');
        }

        // The institution is the tenant itself: it is not a scoped model.
        $found = Institution::query()->where('uuid', $institution)->whereNull('suspended_at')->first();

        if ($found === null) {
            throw new ApiException(404, 'not_found');
        }

        // Nobody is signed in on a public page: the tenant scope would match nothing. The elections are those of
        // the institution found above, and only the published ones.
        $elections = Election::withoutInstitutionScope()
            ->where('institution_id', $found->id)
            ->where('status', ElectionStatus::Published->value)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        $logo = $found->logo_file;

        return response()->json([
            'data' => [
                'id' => $found->uuid,
                'name' => $found->name,
                'type' => $found->type->value,
                'logo' => $logo === null ? null : [
                    'sm' => '/media/'.ImageReEncoder::nameOf($logo, 64),
                    'md' => '/media/'.ImageReEncoder::nameOf($logo, 160),
                    'lg' => '/media/'.ImageReEncoder::nameOf($logo, 480),
                ],
                'description' => $found->description,
                'city' => $found->city,
                'elections' => $elections->map(static fn (Election $election): array => [
                    'id' => $election->uuid,
                    'title' => $election->title,
                    'published_at' => $election->published_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                    'starts_at' => $election->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
                    'ends_at' => $election->ends_at->utc()->format('Y-m-d\TH:i:s\Z'),
                    'timezone' => $election->timezone,
                    'language' => $election->language,
                ])->all(),
            ],
        ], 200, ['Cache-Control' => 'no-cache']);
    }
}
