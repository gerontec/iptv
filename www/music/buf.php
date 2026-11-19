<?php
// Generic on-demand HLS buffer entry: buf.php?ch=<id>
// Marks the channel as watched, asks tvbuf-start.path to start tvbuf@<id> if needed,
// and serves the buffer playlist with segment URIs pointing to buf/<id>/.
$ch = $_GET['ch'] ?? '';
if (!preg_match('/^[a-z0-9-]{1,40}$/', $ch) || !is_file("/etc/tvbuf/$ch.conf")) {
    http_response_code(404);
    exit("unknown channel\n");
}
$pl  = "/srv/tvbuf/$ch/index.m3u8";
$run = '/run/tvbuf';
@touch("$run/seen/$ch");

function fresh(string $pl): bool {
    clearstatcache();
    return is_file($pl) && time() - filemtime($pl) < 30
        && substr_count((string)@file_get_contents($pl), '.ts') >= 1;
}

if (!fresh($pl)) {
    @file_put_contents("$run/want/$ch", time() . "\n");
    for ($i = 0; $i < 40 && !fresh($pl); $i++) usleep(500000);
    if (!fresh($pl)) {
        http_response_code(503);
        header('Retry-After: 3');
        exit("buffer starting\n");
    }
}

header('Content-Type: application/vnd.apple.mpegurl');
header('Cache-Control: no-cache, no-store');
echo preg_replace('/^(seg_\d+\.ts)$/m', "buf/$ch/$1", file_get_contents($pl));
