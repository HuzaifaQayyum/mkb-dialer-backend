<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class CampaignController extends Controller
{
    public function index()
    {
        return response()->json([
            [
                'id' => 1,
                'name' => 'Real Estate Cold Outreach',
                'status' => 'active',
                'dial_rate' => 2.5,
                'leads_count' => 1250,
                'called_count' => 450,
                'created_at' => now()->subDays(10)->toIso8601String(),
            ],
            [
                'id' => 2,
                'name' => 'Insurance Renewal Campaign',
                'status' => 'paused',
                'dial_rate' => 1.5,
                'leads_count' => 840,
                'called_count' => 840,
                'created_at' => now()->subDays(5)->toIso8601String(),
            ]
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'dial_rate' => 'required|numeric|min:0.5|max:10',
            'leads_count' => 'required|integer|min:0',
        ]);

        return response()->json([
            'message' => 'Campaign created successfully.',
            'campaign' => array_merge($validated, [
                'id' => rand(3, 999),
                'status' => 'paused',
                'called_count' => 0,
                'created_at' => now()->toIso8601String(),
            ])
        ], 201);
    }
}
