#!/bin/bash
# Stop on-demand HLS buffers nobody has requested for IDLE seconds.
# Viewers' playlist requests touch /run/tvbuf/<ch>.seen (see /var/www/it/<ch>.php).
IDLE=300
CHANNELS="rai3bz"
for ch in $CHANNELS; do
    svc=$ch-hls.service
    systemctl is-active -q $svc || continue
    seen=/run/tvbuf/$ch.seen
    age=$(( $(date +%s) - $(stat -c %Y "$seen" 2>/dev/null || echo 0) ))
    if [ "$age" -gt "$IDLE" ]; then
        logger -t tvbuf "stop $svc: no viewer for ${age}s"
        systemctl stop $svc
    fi
done

# generic buffers tvbuf@<id> (music.heissa.de); buf.php touches /run/tvbuf/seen/<id>
for unit in $(systemctl list-units --no-legend --state=active,activating "tvbuf@*" | awk "{print \$1}"); do
    id=${unit#tvbuf@}; id=${id%.service}
    age=$(( $(date +%s) - $(stat -c %Y /run/tvbuf/seen/$id 2>/dev/null || echo 0) ))
    if [ "$age" -gt "$IDLE" ]; then
        logger -t tvbuf "stop $unit: no viewer for ${age}s"
        systemctl stop "$unit"
    fi
done
