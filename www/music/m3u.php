<?php
// One-channel playlist for VLC/Kodi: m3u.php?ch=<id>
$ch = $_GET['ch'] ?? '';
if (!preg_match('/^[a-z0-9-]{1,40}$/', $ch) || !is_file("/etc/tvbuf/$ch.conf")) { http_response_code(404); exit; }
$name = trim(substr((string)strtok(file_get_contents("/etc/tvbuf/$ch.conf"), "\n"), 1));
header('Content-Type: audio/mpegurl');
header("Content-Disposition: inline; filename=\"$ch.m3u\"");
echo "#EXTM3U\n#EXTINF:-1 group-title=\"Music\",$name\nhttps://music.heissa.de/buf.php?ch=$ch\n";
