<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Contact;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        // Campaign-owned leads are walled off from the company-wide list: a
        // CSV imported into a campaign must not spill into everyone's contacts.
        // Pass ?include_campaign_leads=1 to see them anyway (admin views).
        $contacts = Contact::query()
            ->unless($request->boolean('include_campaign_leads'), fn ($q) => $q->whereNull('campaign_id'))
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($contacts->map->toLeadPayload());
    }

    public function store(Request $request)
    {
        // 1. Check if the payload is an array of contacts (Bulk import)
        if ($request->has('contacts') && is_array($request->input('contacts'))) {
            $contactsData = $request->input('contacts');
            
            // Sanitize contacts: clean empty strings and validate email fields before validation rules run
            foreach ($contactsData as &$contactData) {
                if (isset($contactData['email'])) {
                    $email = trim($contactData['email']);
                    $contactData['email'] = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
                }
                if (isset($contactData['tags']) && is_string($contactData['tags'])) {
                    $contactData['tags'] = array_filter(array_map('trim', explode(',', $contactData['tags'])));
                }
                if (!isset($contactData['tags'])) {
                    $contactData['tags'] = [];
                }
            }
            
            // Re-merge sanitized contacts into request
            $request->merge(['contacts' => $contactsData]);

            $validated = $request->validate([
                'contacts' => 'required|array',
                'contacts.*.name' => 'required|string|max:255',
                'contacts.*.phone' => 'required|string|max:50',
                'contacts.*.email' => 'nullable|string|email|max:255',
                'contacts.*.tags' => 'nullable|array',
            ]);

            $created = [];
            foreach ($validated['contacts'] as $c) {
                $created[] = Contact::create([
                    'name' => $c['name'],
                    'phone' => $c['phone'],
                    'email' => $c['email'] ?? null,
                    'tags' => $c['tags'] ?? [],
                ]);
            }

            return response()->json([
                'message' => 'Contacts imported successfully.',
                'count' => count($created),
                'contacts' => $created
            ], 201);
        }

        // 2. Single contact creation
        // Sanitize email if empty string
        if ($request->has('email') && trim($request->input('email')) === '') {
            $request->merge(['email' => null]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:50',
            'email' => 'nullable|string|email|max:255',
            'tags' => 'nullable|array',
        ]);

        $contact = Contact::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'] ?? null,
            'tags' => $validated['tags'] ?? [],
        ]);

        return response()->json($contact, 201);
    }

    public function update(Request $request, $id)
    {
        $contact = Contact::find($id);
        if (! $contact) {
            return response()->json(['message' => 'Contact not found.'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:50',
            'email' => 'sometimes|nullable|email|max:255',
            'company_name' => 'sometimes|nullable|string|max:255',
            'address' => 'sometimes|nullable|string|max:255',
            'city' => 'sometimes|nullable|string|max:255',
            'state' => 'sometimes|nullable|string|max:255',
            'notes' => 'sometimes|nullable|string|max:5000',
            'stage' => 'sometimes|nullable|string|max:64',
            'value' => 'sometimes|nullable|numeric|min:0',
            'callback_date' => 'sometimes|nullable|date',
            'tags' => 'sometimes|nullable|array',
        ]);

        $contact->update($validated);

        return response()->json($contact->fresh()->toLeadPayload());
    }

    public function destroy($id)
    {
        $contact = Contact::findOrFail($id);
        $contact->delete();

        return response()->json([
            'message' => 'Contact deleted successfully.'
        ]);
    }
}
