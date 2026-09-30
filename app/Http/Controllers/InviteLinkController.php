<?php

namespace App\Http\Controllers;

use App\Services\InviteService;
use App\Services\SecurityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The link in invitation emails / WhatsApp: /i/{token}.
 * Guests see a short summary and sign in; signed-in suppliers are bound to the invite
 * (only if their email/mobile matches what the buyer entered) and taken to the RFQ.
 */
class InviteLinkController extends Controller
{
    public function show(Request $request, string $token, InviteService $invites): View|RedirectResponse
    {
        $invite = $invites->findByToken($token);

        if (! $invite || ! $invite->rfq || $invite->rfq->isDraft()) {
            SecurityLog::info('invite_token_invalid', ['token_prefix' => substr($token, 0, 6)]);
            abort(404);
        }

        if (! $request->user()) {
            $request->session()->put('url.intended', $request->fullUrl());

            return view('invites.guest', ['invite' => $invite, 'rfq' => $invite->rfq]);
        }

        $result = $invites->claim($invite, $request->user());

        if (! $result['ok']) {
            return view('invites.denied', ['result' => $result, 'rfq' => $invite->rfq]);
        }

        // Make sure the user is acting as the supplier company that owns this invite.
        $supplier = $invites->supplierOrgFor($request->user());
        if ($request->user()->current_organization_id !== $supplier->id) {
            $request->user()->switchOrganization($supplier);
        }

        return redirect()->route('supplier.rfqs.show', $invite->id);
    }
}
