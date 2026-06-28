<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Str;

class CompanyController extends Controller
{
    public function index()
    {
        // Retrieve companies linked to the main Admin User (ID 1) for session mocking
        $user = User::find(1);
        if ($user) {
            return response()->json($user->companies()->get());
        }
        return response()->json(Company::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $slug = Str::slug($validated['name']);
        
        // Ensure slug is unique
        $originalSlug = $slug;
        $count = 1;
        while (Company::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $count++;
        }

        $company = Company::create([
            'name' => $validated['name'],
            'slug' => $slug,
        ]);

        // Automatically link the main admin user to the new company
        $admin = User::find(1);
        if ($admin) {
            $company->users()->attach($admin, [
                'role' => 'Admin',
                'status' => 'available',
                'queue_eligible' => false,
            ]);
        }

        return response()->json($company, 201);
    }
}
