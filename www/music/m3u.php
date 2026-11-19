<?php
// One-channel playlist for VLC/Kodi: m3u.php?ch=<id> (works on any <sub>.heissa.de site with buf.php)
$ch = $_GET['ch'] ?? '';
if (!preg_match('/^[a-z0-9-]{1,40}$/', $ch) || !is_file("/etc/tvbuf/$ch.conf")) { http_response_code(404); exit; }
$host = preg_match('/^[a-z0-9-]+\.heissa\.de$/', $_SERVER['HTTP_HOST'] ?? '') ? $_SERVER['HTTP_HOST'] : 'music.heissa.de';
$name = trim(substr((string)strtok(file_get_contents("/etc/tvbuf/$ch.conf"), "\n"), 1));
header('Content-Type: audio/mpegurl');
header("Content-Disposition: inline; filename=\"$ch.m3u\"");
echo "#EXTM3U\n#EXTINF:-1,$name\nhttps://$host/buf.php?ch=$ch\n";
