<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Company;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Http\Middleware\TenantScopeMiddleware;

class AgentController extends Controller
{
    private function getActiveCompany()
    {
        $companyId = TenantScopeMiddleware::$companyId;
        return $companyId ? Company::find($companyId) : null;
    }

    public function index()
    {
        $company = $this->getActiveCompany();
        if (!$company) {
            return response()->json([]);
        }

        // Lazy Sweep: Auto-disconnect agents who haven't pinged in the last 20 seconds
        DB::table('company_user')
            ->where('company_id', $company->id)
            ->where('last_seen', '<', DB::raw('DATE_SUB(NOW(), INTERVAL 20 SECOND)'))
            ->where('status', '!=', 'inactive')
            ->update(['status' => 'inactive']);

        $users = $company->users()->get()->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role,
                'status' => $user->pivot->status,
                'queue_eligible' => (bool)$user->pivot->queue_eligible,
            ];
        });

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $company = $this->getActiveCompany();
        if (!$company) {
            return response()->json(['error' => 'No active company found'], 400);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'role' => 'required|in:Admin,Manager,Agent',
        ]);

        // Find or create user globally
        $user = User::where('email', $validated['email'])->first();
        if (!$user) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make('password_123'),
            ]);
        }

        // Link user to this company if not already linked (default to inactive status)
        if (!$company->users()->where('user_id', $user->id)->exists()) {
            $company->users()->attach($user, [
                'role' => $validated['role'],
                'status' => 'inactive',
                'queue_eligible' => ($validated['role'] === 'Agent'),
                'last_seen' => DB::raw('NOW()'),
            ]);
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $validated['role'],
            'status' => 'inactive',
            'queue_eligible' => ($validated['role'] === 'Agent'),
        ], 201);
    }

    public function updateStatus(Request $request, $id)
    {
        if (!is_numeric($id)) {
            return response()->json(['error' => 'Invalid user ID format'], 400);
        }

        $company = $this->getActiveCompany();
        if (!$company) {
            return response()->json(['error' => 'No active company found'], 400);
        }

        $validated = $request->validate([
            'status' => 'required|in:online,inactive',
        ]);

        $company->users()->updateExistingPivot($id, [
            'status' => $validated['status'],
            'last_seen' => DB::raw('NOW()'),
        ]);

        return response()->json([
            'message' => 'Agent status updated successfully.',
            'agent_id' => (int)$id,
            'status' => $validated['status'],
        ]);
    }

    public function updateQueueEligibility(Request $request, $id)
    {
        if (!is_numeric($id)) {
            return response()->json(['error' => 'Invalid user ID format'], 400);
        }

        $company = $this->getActiveCompany();
        if (!$company) {
            return response()->json(['error' => 'No active company found'], 400);
        }

        $validated = $request->validate([
            'queue_eligible' => 'required|boolean',
        ]);

        $company->users()->updateExistingPivot($id, [
            'queue_eligible' => $validated['queue_eligible']
        ]);

        return response()->json([
            'message' => 'Queue eligibility updated successfully.',
            'agent_id' => (int)$id,
            'queue_eligible' => (bool)$validated['queue_eligible'],
        ]);
    }

    public function heartbeat(Request $request, $id)
    {
        if (!is_numeric($id)) {
            return response()->json(['error' => 'Invalid user ID format'], 400);
        }

        $company = $this->getActiveCompany();
        if (!$company) {
            return response()->json(['error' => 'No active company found'], 400);
        }

        $company->users()->updateExistingPivot($id, [
            'last_seen' => DB::raw('NOW()'),
        ]);

        return response()->json([
            'message' => 'Heartbeat received.',
            'agent_id' => (int)$id,
            'last_seen' => now()->toIso8601String(),
        ]);
    }

    public function destroy($id)
    {
        if (!is_numeric($id)) {
            return response()->json(['error' => 'Invalid user ID format'], 400);
        }

        $company = $this->getActiveCompany();
        if (!$company) {
            return response()->json(['error' => 'No active company found'], 400);
        }

        $company->users()->detach($id);

        return response()->json([
            'message' => 'Team member removed from workspace.'
        ]);
    }
}
