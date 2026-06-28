# MKB Dialer — Backend API
> Laravel 11 REST API with Laravel Reverb (WebSocket) for real-time agent presence. Includes Asterisk PBX integration for live SIP trunk management via Docker.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 11 |
| Real-time | Laravel Reverb (WebSocket server) |
| Auth | Laravel Sanctum (token-based) |
| Database | SQLite (dev) / MySQL or PostgreSQL (prod) |
| Queue | Database queue driver |
| Telephony | Asterisk 20 (PJSIP) running in Docker |
| PHP | 8.2+ |

---

## Architecture Overview

```
Frontend (React) ──HTTP──► Laravel API ──writes──► pjsip.conf
                                │                        │
                          WebSocket (Reverb)       docker exec asterisk
                                │                   pjsip reload
                           Agent browsers
```

When SIP credentials are updated through the UI, the Laravel backend:
1. Saves the credentials to `storage/app/sip_settings.json`
2. Dynamically rewrites the Asterisk `pjsip.conf` file
3. Hot-reloads Asterisk via `docker exec asterisk asterisk -rx "pjsip reload"` (zero downtime)

---

## Prerequisites

- PHP 8.2+
- Composer 2+
- Docker & Docker Compose (for Asterisk PBX)
- SQLite (built into PHP) **or** MySQL/PostgreSQL for production
- Node.js 18+ (for Vite asset compilation)

---

## 1. Asterisk Setup (Docker)

Asterisk must be running before starting the Laravel backend.

### Start Asterisk container

```bash
docker run -d \
  --name asterisk \
  --network host \
  -v /Users/huzaifa/Documents/projects/asterisk-config:/etc/asterisk \
  andrius/asterisk:latest
```

> **Note:** The `-v` volume mount points to the `asterisk-config/` folder inside this repository. Laravel writes updated `pjsip.conf` to that path and then hot-reloads Asterisk.

### Verify Asterisk is running

```bash
docker exec asterisk asterisk -rx "pjsip show endpoints"
```

### Asterisk config files

The `asterisk-config/` directory contains:

| File | Purpose |
|---|---|
| `pjsip.conf` | SIP endpoints, trunks (Twilio, Telnyx, custom), transports |
| `extensions.conf` | Dialplan — inbound/outbound call routing |
| `ari.conf` | ARI (Asterisk REST Interface) credentials |
| `http.conf` | HTTP/WebSocket server config for WebRTC |
| `rtp.conf` | RTP port range for media |

### Agent SIP extension (default)

```ini
; Extension 1000 — agent softphone / WebRTC
username: 1000
password: agent_pass
```

---

## 2. Laravel Backend Setup

### Clone the repository

```bash
git clone https://github.com/HuzaifaQayyum/mkb-dialer-backend.git
cd mkb-dialer-backend
```

### Install dependencies

```bash
composer install
npm install
```

### Configure environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```env
APP_NAME="MKB Dialer"
APP_ENV=local
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=sqlite

# WebSocket — Laravel Reverb
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=mkb-dialer
REVERB_APP_KEY=your-reverb-app-key
REVERB_APP_SECRET=your-reverb-app-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http

# Queue
QUEUE_CONNECTION=database

# SIP Provider defaults (overridden by UI settings)
SIP_PROVIDER_DOMAIN=sip.mkbdialer.com
SIP_PROVIDER_PORT=5060
SIP_PROVIDER_USERNAME=trunk_main
```

### Run database migrations

```bash
php artisan migrate
```

### Start all services

Run these **three terminal windows** simultaneously:

**Terminal 1 — Laravel API server:**
```bash
php artisan serve
# http://localhost:8000
```

**Terminal 2 — Reverb WebSocket server:**
```bash
php artisan reverb:start --debug
# ws://localhost:8080
```

**Terminal 3 — Queue worker:**
```bash
php artisan queue:work
```

---

## API Endpoints

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/agents` | List all agents |
| POST | `/api/agents` | Create a new agent |
| PUT | `/api/agents/{id}/status` | Update agent status |
| PUT | `/api/agents/{id}/heartbeat` | Agent heartbeat ping |
| PUT | `/api/agents/{id}/queue-eligibility` | Toggle queue eligibility |
| DELETE | `/api/agents/{id}` | Remove an agent |
| GET | `/api/contacts` | List all contacts |
| POST | `/api/contacts` | Create a contact |
| DELETE | `/api/contacts/{id}` | Delete a contact |
| GET | `/api/campaigns` | List all campaigns |
| POST | `/api/campaigns` | Create a campaign |
| GET | `/api/sip` | Get current SIP credentials |
| PUT | `/api/sip` | Update SIP credentials (rewrites pjsip.conf + reloads Asterisk) |
| GET | `/api/companies` | List companies |
| POST | `/api/companies` | Create a company |
| POST | `/api/invitations` | Send a team invitation |
| POST | `/api/invitations/accept` | Accept an invitation |

---

## SIP / Trunk Configuration

The `/api/sip` endpoint manages the live Asterisk trunk. Supported providers in `pjsip.conf`:

| Provider | SIP Contact |
|---|---|
| **Twilio** | `sip:your-domain.pstn.twilio.com:5060` |
| **Telnyx** | `sip:sip.telnyx.com:5060` |
| **Generic / Custom** | Configurable via the Settings UI |

To update credentials:
```bash
curl -X PUT http://localhost:8000/api/sip \
  -H "Content-Type: application/json" \
  -d '{"sip_domain":"sip.telnyx.com","sip_port":5060,"username":"your_user","password":"your_pass"}'
```

This writes `pjsip.conf` and immediately runs:
```bash
docker exec asterisk asterisk -rx "pjsip reload"
```

---

## Production Deployment

### 1. Set production environment

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.your-domain.com
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=mkb_dialer
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password
```

### 2. Optimize Laravel

```bash
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
```

### 3. Supervise processes (Supervisor)

```ini
[program:mkb-queue]
command=php /var/www/mkb-dialer-backend/artisan queue:work --tries=3
autostart=true
autorestart=true
numprocs=2
stdout_logfile=/var/log/mkb-queue.log

[program:mkb-reverb]
command=php /var/www/mkb-dialer-backend/artisan reverb:start
autostart=true
autorestart=true
stdout_logfile=/var/log/mkb-reverb.log
```

```bash
supervisorctl reread && supervisorctl update && supervisorctl start all
```

### 4. Nginx config

```nginx
server {
    listen 80;
    server_name api.your-domain.com;
    root /var/www/mkb-dialer-backend/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

---

## Environment Variables Reference

| Variable | Description | Default |
|---|---|---|
| `APP_KEY` | Laravel encryption key | auto-generated |
| `DB_CONNECTION` | Database driver | `sqlite` |
| `BROADCAST_CONNECTION` | Must be `reverb` for WebSocket | `log` |
| `REVERB_APP_KEY` | Reverb auth key | — |
| `REVERB_APP_SECRET` | Reverb secret | — |
| `REVERB_PORT` | WebSocket port | `8080` |
| `QUEUE_CONNECTION` | Queue driver | `database` |
| `SIP_PROVIDER_DOMAIN` | Default SIP domain | `sip.mkbdialer.com` |
| `SIP_PROVIDER_PORT` | Default SIP port | `5060` |

---

## License

MIT
