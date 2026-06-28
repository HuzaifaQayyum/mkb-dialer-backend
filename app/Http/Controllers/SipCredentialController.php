<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SipCredentialController extends Controller
{
    private function getSettingsPath()
    {
        return storage_path('app/sip_settings.json');
    }

    public function show()
    {
        $path = $this->getSettingsPath();
        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            // Hide password for security
            if (isset($data['password'])) {
                unset($data['password']);
            }
            return response()->json($data);
        }

        return response()->json([
            'sip_domain' => env('SIP_PROVIDER_DOMAIN', 'sip.mkbdialer.com'),
            'sip_port' => (int) env('SIP_PROVIDER_PORT', 5060),
            'username' => env('SIP_PROVIDER_USERNAME', 'trunk_main'),
            'codec_priority' => ['ulaw', 'alaw', 'g729'],
            'nat_support' => true,
            'updated_at' => now()->subDays(2)->toIso8601String(),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'sip_domain' => 'required|string',
            'sip_port' => 'required|integer|min:1|max:65535',
            'username' => 'required|string|max:255',
            'password' => 'nullable|string|min:4',
        ]);

        $path = $this->getSettingsPath();
        $existing = file_exists($path) ? json_decode(file_get_contents($path), true) : [];
        
        $newSettings = array_merge($existing, $validated, [
            'nat_support' => true,
            'updated_at' => now()->toIso8601String(),
        ]);

        // If no password provided, keep the existing one
        if (empty($validated['password']) && isset($existing['password'])) {
            $newSettings['password'] = $existing['password'];
        }

        // Save local JSON backup
        file_put_contents($path, json_encode($newSettings, JSON_PRETTY_PRINT));

        // Write directly to Asterisk mounted pjsip.conf
        $asteriskConfigPath = '/Users/huzaifa/Documents/projects/asterisk-config/pjsip.conf';
        $username = $newSettings['username'];
        $password = $newSettings['password'] ?? 'placeholder_pass';
        $domain = $newSettings['sip_domain'];
        $port = $newSettings['sip_port'];

        $pjsipTemplate = <<<EOT
[global]
type=global
user_agent=MKB-Dialer

; --- TRANSPORTS ---
[transport-udp]
type=transport
protocol=udp
bind=0.0.0.0:5060

[transport-ws]
type=transport
protocol=ws
bind=0.0.0.0:5060

; --- AGENT EXTENSION 1000 (Softphone / WebRTC) ---
[1000]
type=endpoint
context=from-internal
disallow=all
allow=ulaw,alaw,g722
auth=1000-auth
aors=1000-aor

[1000-auth]
type=auth
auth_type=userpass
username=1000
password=agent_pass

[1000-aor]
type=aor
max_contacts=5

; --- DYNAMIC SIP TRUNK (WEB CONFIGURED) ---
[sip-trunk]
type=endpoint
context=from-trunk
disallow=all
allow=ulaw,alaw
outbound_auth=sip-trunk-auth
aors=sip-trunk-aor

[sip-trunk-auth]
type=auth
auth_type=userpass
username={$username}
password={$password}

[sip-trunk-aor]
type=aor
contact=sip:{$domain}:{$port}
EOT;

        try {
            // Write updated pjsip.conf
            file_put_contents($asteriskConfigPath, $pjsipTemplate);
            
            // Reload Asterisk PJSIP configuration in real-time
            shell_exec('docker exec asterisk asterisk -rx "pjsip reload"');
            
            $reloadStatus = 'Asterisk reloaded successfully';
        } catch (\Exception $e) {
            $reloadStatus = 'Failed to write Asterisk config or reload: ' . $e->getMessage();
        }

        return response()->json([
            'message' => 'SIP Credentials updated successfully.',
            'reload_status' => $reloadStatus,
            'settings' => $newSettings
        ]);
    }
}
