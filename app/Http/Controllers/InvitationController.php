<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Invitation;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use App\Http\Middleware\TenantScopeMiddleware;

class InvitationController extends Controller
{
    public function store(Request $request)
    {
        $companyId = TenantScopeMiddleware::$companyId;
        if (!$companyId) {
            return response()->json(['error' => 'No active company context'], 400);
        }

        $validated = $request->validate([
            'email' => 'required|string|email|max:255',
            'role' => 'required|in:Admin,Manager,Agent',
        ]);

        $company = Company::findOrFail($companyId);

        // Create Invitation
        $invitation = Invitation::create([
            'company_id' => $company->id,
            'email' => $validated['email'],
            'role' => $validated['role'],
            'token' => Str::random(32),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        // Mock invite URL
        $inviteUrl = "http://localhost:8081/accept-invite?token=" . $invitation->token;

        return response()->json([
            'message' => 'Invitation created and sent.',
            'invitation' => $invitation,
            'invite_url' => $inviteUrl
        ], 201);
    }

    public function accept(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'name' => 'nullable|string|max:255',
            'password' => 'nullable|string|min:6',
        ]);

        $invitation = Invitation::where('token', $validated['token'])
                                ->where('status', 'pending')
                                ->firstOrFail();

        if ($invitation->expires_at->isPast()) {
            $invitation->status = 'expired';
            $invitation->save();
            return response()->json(['error' => 'Invitation has expired.'], 400);
        }

        $company = Company::findOrFail($invitation->company_id);

        // Find or create user
        $user = User::where('email', $invitation->email)->first();
        if (!$user) {
            $request->validate([
                'name' => 'required|string|max:255',
                'password' => 'required|string|min:6',
            ]);
            $user = User::create([
                'name' => $validated['name'],
                'email' => $invitation->email,
                'password' => Hash::make($validated['password']),
            ]);
        }

        // Link user to company
        if (!$company->users()->where('user_id', $user->id)->exists()) {
            $company->users()->attach($user, [
                'role' => $invitation->role,
                'status' => 'offline',
                'queue_eligible' => ($invitation->role === 'Agent'),
            ]);
        }

        // Update invitation status
        $invitation->status = 'accepted';
        $invitation->save();

        return response()->json([
            'message' => 'Invitation accepted successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'company' => $company
        ]);
    }
}
