#!/bin/bash
# Start the buffers viewers asked for (buf.php drops /run/tvbuf/want/<id>).
MAX=12   # protect the home line: at most this many buffers at once
for f in /run/tvbuf/want/*; do
    [ -e "$f" ] || continue
    id=${f##*/}; rm -f "$f"
    [[ $id =~ ^[a-z0-9-]+$ ]] && [ -f /etc/tvbuf/$id.conf ] || continue
    systemctl is-active -q tvbuf@$id && continue
    n=$(systemctl list-units --no-legend --state=active,activating "tvbuf@*" | wc -l)
    if [ "$n" -ge "$MAX" ]; then logger -t tvbuf "refuse $id: $n buffers running"; continue; fi
    logger -t tvbuf "start tvbuf@$id"
    systemctl start --no-block tvbuf@$id
done
