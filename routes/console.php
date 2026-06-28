<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('asterisk:configure-trunk', function () {
    $configs = [
        'twilio' => [
            'domain' => env('TWILIO_SIP_DOMAIN'),
            'port' => env('TWILIO_SIP_PORT', 5060),
            'username' => env('TWILIO_SIP_USERNAME', 'twilio_user'),
            'password' => env('TWILIO_SIP_PASSWORD', 'twilio_pass'),
        ],
        'telnyx' => [
            'domain' => env('TELNYX_SIP_DOMAIN'),
            'port' => env('TELNYX_SIP_PORT', 5060),
            'username' => env('TELNYX_SIP_USERNAME', 'telnyx_user'),
            'password' => env('TELNYX_SIP_PASSWORD', 'telnyx_pass'),
        ],
        'generic' => [
            'domain' => env('GENERIC_SIP_DOMAIN'),
            'port' => env('GENERIC_SIP_PORT', 5060),
            'username' => env('GENERIC_SIP_USERNAME', 'generic_user'),
            'password' => env('GENERIC_SIP_PASSWORD', 'generic_pass'),
        ],
    ];

    $this->info("Generating multi-provider Asterisk configuration...");

    $pjsipContent = <<<EOT
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

; --- AGENT EXTENSION 1000 ---
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
EOT;

    foreach ($configs as $name => $c) {
        $domain = $c['domain'] ?? 'sip.provider.com';
        $port = $c['port'];
        $user = $c['username'];
        $pass = $c['password'];

        $pjsipContent .= "\n\n; --- {$name} Trunk ---\n";
        $pjsipContent .= "[{$name}-trunk]\n";
        $pjsipContent .= "type=endpoint\n";
        $pjsipContent .= "context=from-trunk\n";
        $pjsipContent .= "disallow=all\n";
        $pjsipContent .= "allow=ulaw,alaw\n";
        $pjsipContent .= "outbound_auth={$name}-trunk-auth\n";
        $pjsipContent .= "aors={$name}-trunk-aor\n\n";

        $pjsipContent .= "[{$name}-trunk-auth]\n";
        $pjsipContent .= "type=auth\n";
        $pjsipContent .= "auth_type=userpass\n";
        $pjsipContent .= "username={$user}\n";
        $pjsipContent .= "password={$pass}\n\n";

        $pjsipContent .= "[{$name}-trunk-aor]\n";
        $pjsipContent .= "type=aor\n";
        $pjsipContent .= "contact=sip:{$domain}:{$port}\n";
    }

    $asteriskConfigPath = '/Users/huzaifa/Documents/projects/asterisk-config/pjsip.conf';
    
    try {
        file_put_contents($asteriskConfigPath, $pjsipContent);
        $this->info('Config file updated successfully at: ' . $asteriskConfigPath);
        
        $output = shell_exec('docker exec asterisk asterisk -rx "pjsip reload"');
        $this->info('Asterisk configuration reload triggered:');
        $this->line($output ?? 'No output returned');
    } catch (\Exception $e) {
        $this->error('Failed to configure Asterisk: ' . $e->getMessage());
    }
})->purpose('Configure Asterisk SIP Trunk using SaaS .env credentials');
