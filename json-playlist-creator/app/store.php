<?php
/**
 * File storage. Writes are atomic (temporary file + rename), so a crash never leaves half a file; catalog saves
 * are serialized with a lock and keep a dated backup of what they replace.
 */

const JP_BACKUPS_KEPT = 40;

function jp_data_dir($sub = '')
{
    $d = JP_DATA . ($sub !== '' ? "/$sub" : '');
    if (!is_dir($d)) @mkdir($d, 0770, true);
    if ($sub === '') jp_protect_dir($d);
    return $d;
}

/** Puts "never serve this folder" files in it, for web servers that read them (Apache). nginx needs its own rule. */
function jp_protect_dir($d)
{
    if (!is_file("$d/.htaccess")) @file_put_contents("$d/.htaccess", "Require all denied\n");
    if (!is_file("$d/index.html")) @file_put_contents("$d/index.html", '');
}

function jp_catalog_path()
{
    return jp_data_dir() . '/catalog.json';
}

/** Writes [data] to [path] atomically. Throws when it can't. */
function jp_write_atomic($path, $data, $mode = 0660)
{
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $data) === false) {
        throw new RuntimeException('Could not write ' . basename($path) . ' (is ' . dirname($path) . ' writable by PHP, and is the disk full?).');
    }
    @chmod($tmp, $mode);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not replace ' . basename($path) . '.');
    }
}

function jp_read_json($path)
{
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

/** The catalog as stored (normalized), the newest readable backup if it's damaged, or an empty one. */
function jp_read_catalog()
{
    $doc = jp_read_json(jp_catalog_path());
    if ($doc === null && is_file(jp_catalog_path())) {
        foreach (jp_backups() as $b) {
            $doc = jp_read_json(jp_backup_dir() . '/' . $b['name']);
            if ($doc !== null) { error_log('json-playlist-creator: catalog.json is damaged, serving backup ' . $b['name']); break; }
        }
    }
    return jp_normalize($doc ?? jp_default_catalog());
}

function jp_backup_dir()
{
    return jp_data_dir('backups');
}

/** Newest first: [{name, size, time}]. */
function jp_backups()
{
    $files = glob(jp_backup_dir() . '/catalog-*.json') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a) ?: strcmp($b, $a));
    return array_map(fn($f) => ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)], $files);
}

/** Raw text of the stored catalog and its revision (hash), for conflict-safe saving. */
function jp_catalog_revision()
{
    $raw = (string)@file_get_contents(jp_catalog_path());
    return [$raw, md5($raw)];
}

/**
 * Saves the catalog if nobody saved in between ([baseRev] = revision the editor loaded; null = don't check).
 * Returns ['conflict' => bool, 'rev' => new revision].
 */
function jp_save_catalog(array $doc, $baseRev = null)
{
    $lock = fopen(jp_data_dir() . '/.catalog.lock', 'c');
    if (!$lock) throw new RuntimeException('Could not lock the catalog (is data/ writable by PHP?).');
    flock($lock, LOCK_EX);
    try {
        [$current, $rev] = jp_catalog_revision();
        if ($baseRev !== null && $rev !== $baseRev) return ['conflict' => true, 'rev' => $rev];
        if ($current !== '') {
            $dir = jp_backup_dir();
            $stamp = date('Ymd-His');
            $name = "$dir/catalog-$stamp.json";
            for ($n = 2; file_exists($name); $n++) $name = "$dir/catalog-$stamp-$n.json";
            jp_write_atomic($name, $current);
            $old = array_slice(jp_backups(), JP_BACKUPS_KEPT);
            foreach ($old as $b) @unlink("$dir/{$b['name']}");
        }
        $json = json_encode(jp_normalize($doc), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) throw new RuntimeException('The catalog could not be encoded.');
        jp_write_atomic(jp_catalog_path(), $json);
        return ['conflict' => false, 'rev' => md5($json)];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Setup record: who accepted which terms, and when. Null before setup. */
function jp_setup_record()
{
    return jp_read_json(jp_data_dir() . '/setup.json');
}

function jp_is_set_up()
{
    return is_file(jp_data_dir() . '/credentials.json') && jp_setup_record() !== null;
}
