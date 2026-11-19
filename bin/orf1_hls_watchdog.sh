#!/bin/bash
# Restart orf1-hls when ffmpeg stops updating the HLS playlist
# (seen 13.11.2026: DTS wrap -> playlist frozen, 1 GB segments per hour).
DIR=/srv/orf1hls
PL=$DIR/orf1.m3u8
MAX_AGE=30          # seconds without playlist update
MAX_SEG=50000000    # bytes; a 2 s segment is < 2 MB
SVC=orf1-hls

# give a freshly started ffmpeg time to produce segments
since=$(systemctl show -p ActiveEnterTimestampMonotonic --value $SVC)
now=$(awk '{printf "%d", $1*1000000}' /proc/uptime)
[ $(( (now - since) / 1000000 )) -lt 60 ] && exit 0

reason=""
if [ ! -f "$PL" ]; then
    reason="playlist missing"
else
    age=$(( $(date +%s) - $(stat -c %Y "$PL") ))
    [ "$age" -gt "$MAX_AGE" ] && reason="playlist ${age}s old"
fi
big=$(find "$DIR" -maxdepth 1 -name 'seg_*.ts' -size +${MAX_SEG}c | head -1)
[ -z "$reason" ] && [ -n "$big" ] && reason="oversized segment $(basename "$big")"

[ -z "$reason" ] && exit 0
logger -t orf1-hls-watchdog "restart $SVC: $reason"
systemctl restart $SVC
# leftovers of the old run are not in the new playlist and never get deleted
find "$DIR" -maxdepth 1 -name 'seg_*.ts' -mmin +10 -delete
find "$DIR" -maxdepth 1 -name 'seg_*.ts' -size +${MAX_SEG}c -delete
