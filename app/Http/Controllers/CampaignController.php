<?php

namespace App\Http\Controllers;

use App\Http\Middleware\TenantScopeMiddleware;
use App\Models\Campaign;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Campaigns own their own lead list and their own calling team.
 *
 * The important invariant: leads imported into a campaign are scoped to it.
 * They never appear in the company-wide contact list, and an agent working
 * campaign A is only ever handed A's numbers.
 */
class CampaignController extends Controller
{
    private function companyId(): ?int
    {
        return TenantScopeMiddleware::$companyId;
    }

    public function index()
    {
        $companyId = $this->companyId();
        if (! $companyId) {
            return response()->json([]);
        }

        $campaigns = Campaign::where('company_id', $companyId)
            ->withCount([
                'leads as leads_count',
                'leads as called_count' => fn ($q) => $q->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))->from('call_attempts')
                        ->whereColumn('call_attempts.contact_id', 'contacts.id');
                }),
            ])
            ->with('agents:id')
            ->latest('id')
            ->get();

        return response()->json($campaigns->map(fn ($c) => $this->serialize($c)));
    }

    public function show(int $id)
    {
        $campaign = $this->findOrFail($id);
        if (! $campaign instanceof Campaign) {
            return $campaign;
        }
        $campaign->loadCount('leads as leads_count')->load('agents:id');

        return response()->json($this->serialize($campaign));
    }

    public function store(Request $request)
    {
        $companyId = $this->companyId();
        if (! $companyId) {
            return response()->json(['message' => 'No active company'], 422);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'nullable|in:call,sms',
            'dial_rate' => 'nullable|numeric|min:0.5|max:10',
            'status' => 'nullable|in:active,paused,completed',
        ]);

        $campaign = Campaign::create([
            'company_id' => $companyId,
            'name' => $validated['name'],
            'type' => $validated['type'] ?? 'call',
            'status' => $validated['status'] ?? 'active',
            'dial_rate' => $validated['dial_rate'] ?? 1.0,
        ]);

        $campaign->leads_count = 0;
        return response()->json($this->serialize($campaign), 201);
    }

    public function update(Request $request, int $id)
    {
        $campaign = $this->findOrFail($id);
        if (! $campaign instanceof Campaign) {
            return $campaign;
        }

        $campaign->update($request->validate([
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|in:active,paused,completed',
            'dial_rate' => 'sometimes|numeric|min:0.5|max:10',
        ]));

        $campaign->loadCount('leads as leads_count')->load('agents:id');
        return response()->json($this->serialize($campaign));
    }

    public function destroy(int $id)
    {
        $campaign = $this->findOrFail($id);
        if (! $campaign instanceof Campaign) {
            return $campaign;
        }
        $campaign->delete(); // leads cascade to null, pivot rows cascade away

        return response()->json(['ok' => true]);
    }

    /**
     * Import leads for one campaign only.
     *
     * Accepts a parsed array (what the CSV upload in the UI sends) or a raw
     * CSV file. Rows without a usable phone number are reported back rather
     * than silently dropped — a dialer that quietly loses half an import is
     * worse than one that refuses it.
     */
    public function importLeads(Request $request, int $id)
    {
        $campaign = $this->findOrFail($id);
        if (! $campaign instanceof Campaign) {
            return $campaign;
        }

        $rows = $request->input('leads');

        if (! is_array($rows) && $request->hasFile('file')) {
            $rows = $this->parseCsv($request->file('file')->getRealPath());
            $campaign->update(['import_file_name' => $request->file('file')->getClientOriginalName()]);
        } elseif ($request->filled('import_file_name')) {
            $campaign->update(['import_file_name' => $request->input('import_file_name')]);
        }

        if (! is_array($rows) || $rows === []) {
            return response()->json(['message' => 'No leads supplied. Send `leads` as an array or upload `file`.'], 422);
        }

        $imported = 0;
        $skipped = [];

        DB::transaction(function () use ($rows, $campaign, &$imported, &$skipped) {
            foreach ($rows as $i => $row) {
                $phone = $this->pick($row, ['phone', 'Phone', 'phone_number', 'number', 'mobile']);
                if (! $phone) {
                    $skipped[] = ['row' => $i + 1, 'reason' => 'missing phone'];
                    continue;
                }

                Contact::create([
                    'company_id' => $campaign->company_id,
                    'campaign_id' => $campaign->id,
                    'name' => $this->pick($row, ['name', 'Name', 'full_name', 'contact_name']) ?? 'Unknown',
                    'phone' => $phone,
                    'email' => $this->pick($row, ['email', 'Email']),
                    'company_name' => $this->pick($row, ['company', 'Company', 'company_name', 'organisation', 'organization']),
                    'address' => $this->pick($row, ['address', 'Address', 'street']),
                    'city' => $this->pick($row, ['city', 'City']),
                    'state' => $this->pick($row, ['state', 'State', 'province', 'region']),
                    'notes' => $this->pick($row, ['notes', 'Notes', 'comment', 'comments']),
                    'stage' => $this->pick($row, ['stage', 'Stage']) ?? 'new',
                    'value' => (float) ($this->pick($row, ['value', 'Value', 'deal_value', 'amount']) ?? 0),
                    'tags' => array_filter(array_map('trim', explode(',', (string) ($this->pick($row, ['tags', 'Tags']) ?? '')))),
                ]);
                $imported++;
            }
        });

        return response()->json([
            'imported' => $imported,
            'skipped' => count($skipped),
            'skippedRows' => array_slice($skipped, 0, 25),
            'leadsCount' => $campaign->leads()->count(),
        ], 201);
    }

    public function leads(Request $request, int $id)
    {
        $campaign = $this->findOrFail($id);
        if (! $campaign instanceof Campaign) {
            return $campaign;
        }

        $leads = $campaign->leads()
            ->when($request->query('stage'), fn ($q, $s) => $q->where('stage', $s))
            ->orderBy('id')
            ->limit((int) $request->query('limit', 500))
            ->get();

        return response()->json($leads->map->toLeadPayload());
    }

    /** Replace the campaign's calling team. */
    public function setAgents(Request $request, int $id)
    {
        $campaign = $this->findOrFail($id);
        if (! $campaign instanceof Campaign) {
            return $campaign;
        }

        $validated = $request->validate([
            'agentIds' => 'present|array',
            'agentIds.*' => 'integer',
        ]);

        // Only members of this company may be assigned.
        $valid = DB::table('company_user')
            ->where('company_id', $campaign->company_id)
            ->whereIn('user_id', $validated['agentIds'])
            ->pluck('user_id')
            ->all();

        $rejected = array_values(array_diff(array_map('intval', $validated['agentIds']), $valid));

        $campaign->agents()->sync($valid);

        return response()->json([
            'ok' => true,
            'agentIds' => array_map('strval', $valid),
            'rejected' => $rejected, // not members of this company
        ]);
    }

    private function findOrFail(int $id)
    {
        $campaign = Campaign::where('company_id', $this->companyId())->find($id);
        return $campaign ?: response()->json(['message' => 'Campaign not found'], 404);
    }

    private function serialize(Campaign $c): array
    {
        return [
            'id' => (string) $c->id,
            'name' => $c->name,
            'type' => $c->type,
            'status' => $c->status,
            'dial_rate' => (float) $c->dial_rate,
            'leadsCount' => (int) ($c->leads_count ?? 0),
            'calledCount' => (int) ($c->called_count ?? 0),
            'importFileName' => $c->import_file_name,
            'agentIds' => $c->relationLoaded('agents')
                ? $c->agents->pluck('id')->map(fn ($i) => (string) $i)->all()
                : [],
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    private function pick(array|object $row, array $keys): ?string
    {
        $row = (array) $row;
        foreach ($keys as $k) {
            if (isset($row[$k]) && trim((string) $row[$k]) !== '') {
                return trim((string) $row[$k]);
            }
        }
        return null;
    }

    private function parseCsv(string $path): array
    {
        $rows = [];
        if (($h = fopen($path, 'r')) === false) {
            return $rows;
        }
        $header = fgetcsv($h);
        if (! $header) {
            fclose($h);
            return $rows;
        }
        $header = array_map(fn ($c) => trim((string) $c), $header);
        while (($line = fgetcsv($h)) !== false) {
            if (count($line) === 1 && trim((string) $line[0]) === '') {
                continue;
            }
            $rows[] = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), null));
        }
        fclose($h);

        return $rows;
    }
}
