<?php
// On-demand HLS buffer on the dell: serves the buffer playlist and starts the buffer if needed.
// Every playlist request marks the channel as watched; tvbuf_idle.sh stops idle buffers.
$ch  = 'rai3bz';
$pl  = "/srv/{$ch}hls/index.m3u8";
$run = '/run/tvbuf';

@touch("$run/$ch.seen");

function fresh(string $pl): bool {
    clearstatcache();
    return is_file($pl) && time() - filemtime($pl) < 30
        && substr_count((string)@file_get_contents($pl), '.ts') >= 2;
}

if (!fresh($pl)) {
    // write + close triggers the systemd path unit, which starts <ch>-hls.service
    @file_put_contents("$run/$ch.want", time() . "\n");
    for ($i = 0; $i < 40 && !fresh($pl); $i++) usleep(500000);
    if (!fresh($pl)) {
        http_response_code(503);
        header('Retry-After: 3');
        exit("buffer starting\n");
    }
}

header('Content-Type: application/vnd.apple.mpegurl');
header('Cache-Control: no-cache, no-store');
echo preg_replace('/^(seg_\d+\.ts)$/m', "$ch/$1", file_get_contents($pl));
