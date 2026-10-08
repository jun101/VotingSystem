<?php

namespace App\Http\Controllers\Users;

use App\Actions\Users\CreateInvitation;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\CreateInvitationRequest;
use App\Http\Requests\Users\ListRequest;
use App\Http\Resources\InvitationResource;
use App\Http\Resources\PageOf;
use App\Models\Invitation;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;

class InvitationController extends Controller
{
    /**
     * The invitations that have not been accepted.
     *
     * Live and expired ones, newest first. The token is never returned. Owner only.
     *
     * @response array{data: list<array{id: string, email: string, role: 'owner'|'manager', invited_by: string|null, created_at: string, expires_at: string, expired: bool}>, meta: array{page: int, per_page: int, total: int}}
     */
    public function index(ListRequest $request): PageOf
    {
        // The tenant scope limits the list to the signed-in owner's institution.
        $page = Invitation::query()
            ->whereNull('accepted_at')
            ->with('inviter')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage(), page: $request->page());

        return PageOf::from($page, InvitationResource::class);
    }

    /**
     * Invite a person.
     *
     * Invites an address to join the institution as owner or manager, and sends the email in the
     * institution's language. A live invitation for the same address is replaced: its link stops
     * working. Owner only. Limited to 20 requests per hour per user.
     */
    #[Response(status: 201, type: "array{data: array{id: string, email: string, role: 'owner'|'manager', invited_by: string|null, created_at: string, expires_at: string, expired: bool}}")]
    public function store(CreateInvitationRequest $request, CreateInvitation $create): JsonResponse
    {
        /** @var User $inviter */
        $inviter = $request->user();
        /** @var array{email: string, role: string} $data */
        $data = $request->validated();

        $invitation = $create($inviter, $data['email'], Role::from($data['role']));

        return (new InvitationResource($invitation))->response()->setStatusCode(201);
    }

    /**
     * Cancel an invitation.
     *
     * Its link stops working; the row is deleted. An invitation that is unknown, already
     * accepted, already cancelled or another institution's answers 404. Owner only.
     */
    public function destroy(Invitation $invitation): HttpResponse
    {
        // Bound through the tenant scope; an accepted invitation is not one that can be cancelled.
        if ($invitation->accepted_at !== null) {
            abort(404);
        }

        $invitation->delete();

        return response()->noContent();
    }
}
