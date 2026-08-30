# Bulk Dialer — how it works and how to run it

## Architecture

Three processes cooperate. Each owns one thing:

| Process | Owns | Why it, and not the others |
|---|---|---|
| **Laravel** (`:8000`) | Pacing decisions, call records, agent assignment | Has the DB, the tenancy scoping, and the transaction needed to hand one agent to exactly one call |
| **mkb-dialer-core** (`:5000`) | The live call | Holds the Stasis WebSocket, which a request/response app cannot |
| **Asterisk** (Docker) | SIP + media | Talks to Telnyx |

```
dialer:work ──originate──► Asterisk ──INVITE──► Telnyx ──► lead's phone
    (Laravel)                  │
                               │ StasisStart (lead answered)
                               ▼
                            core ──"who do I bridge to?"──► Laravel
                               │                               │
                               │◄────── agent extension ───────┘
                               │        (or "nobody" → abandoned)
                               ▼
                     bridge lead + agent
```

The pacer never dials more lines than there are free agents
(`min(concurrency, free_agents × pacing_ratio)`). That is what keeps the
abandoned-call rate near zero.

## Running it

Six processes. Order matters only for Asterisk (must precede core).

```bash
# 1. Asterisk
docker start asterisk        # first time: see "Asterisk container" below

# 2. Laravel API
cd mkb-dialer-backend && php artisan serve

# 3. Pacing daemon  ← the bulk dialer itself; nothing dials without this
cd mkb-dialer-backend && php artisan dialer:work

# 4. Queue worker
cd mkb-dialer-backend && php artisan queue:work

# 5. Telephony core (holds the Stasis websocket)
cd mkb-dialer-core && bun run dev:bun

# 6. Frontend
cd mkb-dialer && bun run dev     # http://localhost:8080
```

Health check, all in one:

```bash
curl -s localhost:8000/api/dialer/session | jq '{asteriskUp, leadsRemaining, agents: [.agents[].status]}'
```

### Asterisk container

The config lives in `../asterisk-config` and is mounted over `/etc/asterisk`.
That directory holds the image's full default config **plus** our five files
(`pjsip.conf`, `extensions.conf`, `ari.conf`, `http.conf`, `rtp.conf`) — mounting
only our five makes Asterisk exit at boot, because `modules.conf` and
`asterisk.conf` go missing.

```bash
docker run -d --name asterisk \
  -p 5060:5060/udp -p 5060:5060/tcp -p 8088:8088 \
  -p 10000-10100:10000-10100/udp \
  -v ~/asterisk-config:/etc/asterisk \
  andrius/asterisk:latest
```

Verify the trunk:

```bash
docker exec asterisk asterisk -rx "pjsip show registrations"   # want: Registered
docker exec asterisk asterisk -rx "pjsip show endpoint telnyx-trunk" | grep Contact
```

## Agents

An agent only receives calls when **all** of these hold:

1. `queue_eligible = 1` on their `company_user` row
2. `status = 'online'` — set by the heartbeat, which the frontend sends every
   few seconds. Miss it for 20s and `AgentController::index` sweeps them offline.
3. `sip_extension` is set (1001–1006 are provisioned in `pjsip.conf`)
4. A softphone is **registered** to that extension

### The phone is the browser

Opening **Agent Station** registers the agent's browser to Asterisk over WebRTC
(`ws://127.0.0.1:8088/ws`) and carries call audio in the page. No desktop
softphone needed — that is what the Browser Phone panel on that page is.

The browser grabs its own credentials from `GET /api/dialer/softphone/{userId}`.
Extensions 1001–1006 are the WebRTC endpoints.

Local dev needs no TLS: `http://localhost` is a secure context, so `getUserMedia`
and plain `ws://` are both allowed. **In production the page will be https, which
forces `wss://` and a real certificate on Asterisk** — that is the one piece of
this that does not carry over from dev.

Dial `600` from the panel for an echo test. Hearing your own voice proves mic,
speaker, and the DTLS/RTP path all work before you trust a live call.

### Desk softphone fallback

Extensions **2001–2003** are plain-UDP endpoints for Zoiper / Linphone / MicroSIP,
kept for testing and for agents who prefer a hardware phone:

| Field | Value |
|---|---|
| Server / domain | your Mac's LAN IP (e.g. `192.168.100.127`), port `5060` |
| Username | `2001` … `2003` |
| Password | `agent2001pass` … `agent2003pass` |

To route a bulk call to one, set that agent's `sip_extension` to `2001`.

Check who's registered: `docker exec asterisk asterisk -rx "pjsip show contacts"`

**Remote agents:** `sip_extension` is passed through verbatim when it contains a
`/`, so an agent on their mobile can be set to
`PJSIP/telnyx-trunk/sip:+15551234567@sip.telnyx.com`.

## Session lifecycle

`closed → active → draining → closed`

- **active** — pacer dials
- **draining** — stops dialing, lets live calls finish, then closes itself.
  Triggered by the Stop button or by `auto_close_at` (office hours).
- Hard stop hangs up every channel immediately.

## Tuning

`.env`:

```
DIALER_CALLER_ID=+15551234567   # a number on your Telnyx account
DIALER_MAX_CONCURRENCY=10        # ceiling no session can exceed
DIALER_LEAD_TIMEOUT=30           # seconds to ring a lead
DIALER_REDIAL_COOLDOWN_HOURS=24  # don't re-dial a contact within this window
```

`pacing_ratio` per session: `1.0` = progressive (one line per free agent, near-zero
abandons). Above `1.0` is predictive — more answers, but some callers pick up with
no agent free. Those are recorded as `abandoned` and surfaced on the dashboard.
Raise it only while watching that number.

## Known limits

- **Inbound does not work** from outside. Asterisk is behind Docker's NAT on
  macOS, so Telnyx cannot reach it unsolicited. Outbound is unaffected.
  Fix with a public host or a tunnel.
- **Supervisor listen-in / whisper is not implemented.** Needs a ChanSpy channel
  originated to the supervisor's softphone. The UI says so rather than faking it.
- **`GET /api/dialer/softphone/{userId}` is unauthenticated** and returns a SIP
  password. That matches the rest of this API, which has no auth at all, but it
  must go behind `auth:sanctum` — with a check that the caller *is* that user —
  before this is reachable from anything but localhost.
- **No answering-machine detection.** Voicemail counts as an answer and burns an
  agent. AMD is the usual next step.
- **No DNC / TCPA enforcement.** Only the redial cooldown exists. Do not run this
  against cold lists without adding a suppression list and calling-hours checks.

## Troubleshooting

| Symptom | Check |
|---|---|
| Nothing dials | Is `dialer:work` running? Is an agent online **and** queue-eligible? |
| `asteriskUp: false` | `docker start asterisk` |
| Calls fail instantly | `docker exec asterisk asterisk -rx "pjsip show registrations"` |
| Lead answers, then drops | No agent free → `abandoned`. Lower concurrency. |
| Agent phone never rings | `pjsip show contacts` — softphone not registered |
| Call connects but **nobody hears anybody** | Codec transcoding. This image ships `res_format_attr_opus` but **not** `codec_opus`, so Asterisk will happily negotiate Opus with the browser and then be unable to convert it to the trunk's ulaw. The agent endpoints are pinned to `allow=ulaw,alaw` for this reason — don't add opus back unless `codec_opus.so` is loaded. Note an **echo test still passes** in this state, because `Echo()` loops media back untouched; only a bridged call fails. |
| Verify audio actually flowed | `docker exec asterisk asterisk -rx "pjsip show channelstats"` during a call. You want non-zero Rx **and** Tx on both legs, with matching counts (agent Rx ≈ trunk Tx). |
| Watch SIP live | `docker exec asterisk asterisk -rx "pjsip set logger on"` then `docker logs -f asterisk` |
