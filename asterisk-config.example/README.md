# Asterisk config (template)

These are the five files this project overrides. **They are a template** — the
live copy carries real SIP credentials and is deliberately kept outside git.

## Setting up a working config directory

Mounting only these five files over `/etc/asterisk` makes Asterisk exit at boot,
because the image's `modules.conf` and `asterisk.conf` go missing. So start from
the image's own config and layer ours on top:

```bash
# 1. Pull the image's full default config out
mkdir -p ~/asterisk-config && cd ~/asterisk-config
docker create --name ast-tmp andrius/asterisk:latest
docker cp ast-tmp:/etc/asterisk/. .
docker rm ast-tmp

# 2. Layer this template over it
cp /path/to/mkb-dialer-backend/asterisk-config.example/*.conf .

# 3. Fill in the placeholders in pjsip.conf:
#    YOUR_TELNYX_SIP_USERNAME, YOUR_TELNYX_SIP_PASSWORD, YOUR_TELNYX_NUMBER
#    (from Telnyx Portal → SIP Connections → your credential connection)

# 4. Run it
docker run -d --name asterisk \
  -p 5060:5060/udp -p 5060:5060/tcp -p 8088:8088 \
  -p 10000-10100:10000-10100/udp \
  -v ~/asterisk-config:/etc/asterisk \
  andrius/asterisk:latest
```

Verify: `docker exec asterisk asterisk -rx "pjsip show registrations"` → `Registered`.

## Before this leaves localhost

These defaults are fine for local development and **not** fine on a public host:

- **Agent SIP passwords are predictable** — `agent<extension>pass`, and the
  pattern is in `config/services.php`. Anyone who reads the repo can register as
  an agent. Change the scheme (and `ASTERISK_AGENT_PW_*`) before exposing 5060.
- **The ARI password is `mkb_pass`** (`ari.conf` + `ASTERISK_ARI_PASSWORD`). ARI
  can originate calls, so this is a toll-fraud vector. Change it and keep 8088
  bound to localhost.
- **`GET /api/dialer/softphone/{userId}` is unauthenticated** and returns a SIP
  password. Put it behind `auth:sanctum` first.
- **Production needs TLS.** An https page forces `wss://`, so Asterisk needs a
  real certificate and a `[transport-wss]`. Locally this is unnecessary because
  `http://localhost` is already a secure context.

## Gotchas worth keeping

- **Codecs are pinned to `ulaw,alaw` on the WebRTC endpoints.** This image has
  `res_format_attr_opus` but not `codec_opus`, so it can negotiate Opus with a
  browser and then fail to transcode it to the trunk — a connected call with
  dead audio. An echo test still passes in that state, because `Echo()` never
  transcodes. Don't add opus back unless `codec_opus.so` is loaded.
- **AOR names must match the extension.** `aors=1001` needs a `[1001]` of
  `type=aor`. Naming it `1001-aor` makes registration fail with `404`.
