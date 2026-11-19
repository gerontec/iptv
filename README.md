# iptv

Small, self-hosted live-TV relay for a home server: cable TV from a FRITZ!Box (SAT>IP/RTSP)
and free-to-air internet streams, re-served as HLS to browsers, VLC and Kodi.

Runs on one Linux box (Debian/Ubuntu, systemd, Apache with mod_php, ffmpeg). No transcoding:
every stream is copied (`-c copy`), so one channel costs about 1 % of a CPU core.

## Where each region is served from

| Region | Served by | Why |
|---|---|---|
| Europe (at, it, ..., cable TV) and music TV | the home server (`<cc>.heissa.de` -> dell) | European sources and the FRITZ!Box cable tuner are reachable from the home line; viewers at home get the stream in one LAN hop over IPv6. |
| USA (`us.heissa.de`) and Russia (`ru.heissa.de`) | heissa.de, a VPS in the USA | US sources need a US exit. Russian channels are blocked inside the EU (sanctions since 2022: EU ISPs and CDNs must not carry them), so they cannot be fetched from a European line at all. heissa relays both itself (local hls-relay, `iptv-country.conf`); the home server is not involved. |

External viewers reach the European sites over IPv4 through heissa.de, which proxies to the home
server over OpenVPN; at home the AAAA record points straight to the home server.

## Building blocks

| Part | Files | What it does |
|---|---|---|
| Cable channel (ORF 1) | `systemd/orf1-hls.service`, `systemd/orf1-http.service` | ffmpeg pulls one DVB-C program from the FRITZ!Box over RTSP and writes a 6 min HLS timeshift to `/srv/orf1hls`. |
| Cable watchdog | `bin/orf1_hls_watchdog.sh`, `systemd/orf1-hls-watchdog.*` | Every minute: restart ffmpeg if the playlist is older than 30 s or a segment is larger than 50 MB (DTS wrap freezes ffmpeg and makes it write one huge segment per hour). Deletes leftovers. |
| On-demand buffer | `systemd/rai3bz-hls.service`, `systemd/rai3bz-hls.path`, `www/it/rai3bz.php` | Internet channel buffered by the server (60 s window), started only when someone asks for it. |
| Idle stop | `bin/tvbuf_idle.sh`, `systemd/tvbuf-idle.*`, `tmpfiles.d/tvbuf.conf` | Every minute: stop buffers nobody requested for 5 min. |
| Generic buffer (music) | `systemd/tvbuf@.service`, `systemd/tvbuf-start.*`, `bin/tvbuf_start.sh`, `tvbuf/*.conf`, `www/music/buf.php` | Same idea for any number of channels: one template unit, one small config per channel, one PHP entry. |
| Player pages | `www/at/`, `www/it/`, `www/music/` | hls.js player page per country, channel buttons, one `.m3u` per channel for VLC/Kodi, `list.m3u` with all channels. |

## On-demand buffer

```
viewer ──GET <ch>.php──> Apache/PHP ──touch /run/tvbuf/<ch>.seen   (every playlist refresh)
                              │
                              └─ playlist missing or older than 30 s?
                                   write /run/tvbuf/<ch>.want ──> <ch>-hls.path ──> <ch>-hls.service (ffmpeg)
                                   wait up to 20 s for >= 2 segments, else 503 + Retry-After

tvbuf-idle.timer (1 min) ──> tvbuf_idle.sh: <ch>.seen older than 300 s ──> systemctl stop <ch>-hls
```

- Cold start measured at about 2.5 s until the first playlist.
- The PHP entry rewrites segment URIs to `<ch>/seg_NNNNN.ts`; `/var/www/it/<ch>` is a symlink to `/srv/<ch>hls`.
- ffmpeg runs with `-rw_timeout 15000000`, so a dead upstream TCP connection is dropped after 15 s and reopened.
- `SuccessExitStatus=255` because ffmpeg exits with 255 on SIGTERM.

### Why buffer at all

Measured with VLC pulling Rai 3 TGR Bozen straight from the CDN: the source playlist only holds
3 × 9.6 s, and from time to time a keep-alive connection to the CDN dies silently (request sent,
never acknowledged). VLC then waits for the TCP timeout (minutes) and playback freezes; the play
button does nothing. Through the server buffer the same channel ran without a single stop, while
the server itself still saw upstream gaps of up to 21 s, absorbed by the 60 s window.

### Cost per channel

Measured on an i7 desktop (20 threads):

| | per channel | 6 channels at once (load test, 25 s) |
|---|---|---|
| CPU | ~1 % of one core | all six at 1.0x real time |
| RAM | 50–75 MB | < 0.5 GB |
| Download | 2–7 Mbit/s (SD to 1080p) | 29 Mbit/s |

With the on-demand buffer nothing is downloaded while nobody watches.

## Generic buffer: tvbuf@<id> (music.heissa.de)

87 free music and culture channels (H.264: classical, culture, jazz, pop & world), each only buffered while someone watches.

- `/etc/tvbuf/<id>.conf`: `SRC=<HLS URL>` and `PROGRAM=<variant index>` (highest variant up to 5 Mbit/s,
  so ffmpeg downloads exactly one variant).
- `tvbuf@.service` runs ffmpeg as `www-data` into `/srv/tvbuf/<id>` (60 s window, 6 s segments).
- `buf.php?ch=<id>` validates the id against `/etc/tvbuf`, touches `/run/tvbuf/seen/<id>`, and if the
  playlist is not fresh drops `/run/tvbuf/want/<id>`. `tvbuf-start.path` (`DirectoryNotEmpty`) runs
  `tvbuf_start.sh`, which starts `tvbuf@<id>` (at most 12 buffers at once to protect the line).
- `tvbuf_idle.sh` stops every `tvbuf@*` without a viewer for 5 min.
- `m3u.php?ch=<id>` returns a one-channel playlist for VLC; `list.m3u` holds all channels.
- Cold start measured at 3–6 s; slow sources (first segment after ~20 s) are covered by the player retrying.

Channel list: built from iptv-org (music category) plus an older own list, each URL checked from the
home line (playlist, one segment, then 20 s with the production ffmpeg command). Add a channel by
dropping a new `/etc/tvbuf/<id>.conf` and adding it to `channels.json` / `list.m3u`.

## Add a channel

1. Copy `systemd/rai3bz-hls.service` and `systemd/rai3bz-hls.path` to `<ch>-hls.*`, set the source
   URL, `/srv/<ch>hls` and `/run/tvbuf/<ch>.want`. For multi-bitrate sources point ffmpeg at one
   variant playlist, otherwise it downloads all variants.
2. Copy `www/it/rai3bz.php` to `<ch>.php` and set `$ch`.
3. `ln -s /srv/<ch>hls /var/www/<site>/<ch>` and add `<ch>` to `CHANNELS` in `bin/tvbuf_idle.sh`.
4. `systemctl daemon-reload && systemctl enable --now <ch>-hls.path`
5. Add the channel to the page (`CH` array in `index.html`) and to `list.m3u`.

Streams the browser may fetch directly (CORS allowed, no stalls) need no buffer: just list the URL.

## Install

```
sudo cp systemd/* /etc/systemd/system/
sudo cp bin/* /usr/local/bin/
sudo cp tmpfiles.d/tvbuf.conf /etc/tmpfiles.d/ && sudo systemd-tmpfiles --create
sudo systemctl daemon-reload
sudo mkdir -p /etc/tvbuf && sudo cp tvbuf/*.conf /etc/tvbuf/
sudo install -d -o www-data -g www-data /srv/tvbuf
sudo systemctl enable --now orf1-hls orf1-http orf1-hls-watchdog.timer rai3bz-hls.path tvbuf-start.path tvbuf-idle.timer
```

Copy `www/<site>/` to the web root of each site. The pages load hls.js 1.5.13 from cdnjs.

## Notes

- Only official, free-to-air streams without geo-blocking are listed. Rai 1/2/3 national and
  Mediaset are blocked outside Italy; a VPN exit that geolocates as Italy would be needed.
- Cable channels are SD 576i and copied as they are (interlaced). If motion judders in the
  browser, deinterlace (`bwdif`) and encode to 50p.
- The `orf1` symlink in the web root points to `/srv/orf1hls`.
