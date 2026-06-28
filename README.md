# mkb-dialer-backend

Laravel 11 API backend with Laravel Reverb (WebSocket) and Asterisk PBX integration via Docker.

---

## Prerequisites

- PHP 8.2+
- Composer 2+
- Docker (for Asterisk)
- Node.js 18+ or Bun

---

## 1. Start Asterisk (Docker)

Asterisk must be running first. The backend writes SIP config directly into the mounted volume.

```bash
docker run -d \
  --name asterisk \
  --network host \
  -v /absolute/path/to/mkb-dialer-backend/asterisk-config:/etc/asterisk \
  andrius/asterisk:latest
```

> Replace `/absolute/path/to/mkb-dialer-backend/asterisk-config` with the real path on your machine.

Verify it's running:

```bash
docker exec asterisk asterisk -rx "core show version"
```

---

## 2. Install Dependencies

```bash
composer install
npm install
```

---

## 3. Configure Environment

```bash
cp .env.example .env
php artisan key:generate
```

Open `.env` and set the following:

```env
APP_NAME="MKB Dialer"
APP_ENV=local
APP_URL=http://localhost:8000

# Database (SQLite — zero config, works out of the box)
DB_CONNECTION=sqlite

# WebSocket — must be "reverb" for real-time features
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=mkb-dialer
REVERB_APP_KEY=mkb-dialer-key
REVERB_APP_SECRET=mkb-dialer-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http

# Queue
QUEUE_CONNECTION=database
```

---

## 4. Run Migrations

```bash
php artisan migrate
```

---

## 5. Start the Servers

You need **three terminals** running at the same time:

```bash
# Terminal 1 — API server (http://localhost:8000)
php artisan serve

# Terminal 2 — WebSocket server (ws://localhost:8080)
php artisan reverb:start --debug

# Terminal 3 — Queue worker
php artisan queue:work
```

The API is now live at `http://localhost:8000`.

---

## 6. Asterisk / SIP Config

The `asterisk-config/` directory contains the live Asterisk configuration files:

| File | Purpose |
|---|---|
| `pjsip.conf` | SIP endpoints, trunks (Twilio, Telnyx, custom), agent extensions |
| `extensions.conf` | Dialplan — call routing rules |
| `ari.conf` | Asterisk REST Interface credentials |
| `http.conf` | HTTP/WebSocket server for WebRTC |
| `rtp.conf` | RTP port range for media |

When SIP credentials are saved via the Settings UI, the backend automatically:
1. Rewrites `asterisk-config/pjsip.conf`
2. Runs `docker exec asterisk asterisk -rx "pjsip reload"` — no restart needed

Default agent SIP extension (for softphones):
```
Extension: 1000
Password:  agent_pass
```

---

## Production

```bash
# Optimize Laravel
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan migrate --force

# Use Supervisor to keep queue worker and Reverb running persistently
# (see /etc/supervisor/conf.d/ for config)
```
