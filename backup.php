#!/usr/bin/env php
<?php
/**
 * backup.php – Xtream Vault Data Backup
 * ───────────────────────────────────────
 * Erstellt ein ZIP-Archiv aller Dateien im data/-Verzeichnis.
 * Behält die letzten 7 Backups, ältere werden automatisch gelöscht.
 *
 * Cronjob (täglich um 3 Uhr):
 *   0 3 * * * /usr/bin/php /var/www/html/xtream/backup.php >> /dev/null 2>&1
 *
 * Manuell:
 *   php backup.php
 */

require_once __DIR__ . '/config.php';

define('BACKUP_DIR',   DATA_DIR . '/backups');
define('BACKUP_KEEP',  7);    // Anzahl der aufzubewahrenden Backups
define('BACKUP_LOG',   DATA_DIR . '/backup.log');

function blog(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
    file_put_contents(BACKUP_LOG, $line . PHP_EOL, FILE_APPEND);
}

// ─── Backup-Verzeichnis anlegen ───────────────────────────────────────────────
if (!is_dir(BACKUP_DIR)) {
    if (!mkdir(BACKUP_DIR, 0755, true)) {
        blog("ERROR: Backup-Verzeichnis konnte nicht erstellt werden: " . BACKUP_DIR);
        exit(1);
    }
}

// ─── ZIP-Extension prüfen ────────────────────────────────────────────────────
if (!class_exists('ZipArchive')) {
    blog("ERROR: PHP-Extension 'zip' nicht verfügbar. Installieren mit: sudo apt install php-zip");
    exit(1);
}

// ─── Backup erstellen ─────────────────────────────────────────────────────────
$timestamp  = date('Y-m-d_H-i-s');
$backupFile = BACKUP_DIR . '/backup_' . $timestamp . '.zip';

blog("=== Xtream Vault Backup gestartet ===");
blog("Ziel: $backupFile");

$zip = new ZipArchive();
if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    blog("ERROR: ZIP-Datei konnte nicht erstellt werden: $backupFile");
    exit(1);
}

// ─── Dateien hinzufügen ───────────────────────────────────────────────────────
$files = [
    'config.json',
    'users.json',
    'queue.json',
    'downloaded.json',
    'downloaded_index.json',
    'download_history.json',
    'library_cache.json',
    'activity.json',
    'rate_limits.json',
    'api_keys.json',
];

$added   = 0;
$skipped = 0;

foreach ($files as $file) {
    $path = DATA_DIR . '/' . $file;
    if (file_exists($path)) {
        $zip->addFile($path, 'data/' . $file);
        $added++;
    } else {
        $skipped++;
    }
}

// Metadaten hinzufügen
$meta = json_encode([
    'created_at' => date('Y-m-d H:i:s'),
    'files'      => $added,
    'version'    => '1.0',
], JSON_PRETTY_PRINT);
$zip->addFromString('backup_info.json', $meta);

$zip->close();

$size = filesize($backupFile);
blog(sprintf("Backup erstellt: %d Dateien, %.1f KB", $added, $size / 1024));

// ─── Alte Backups löschen ─────────────────────────────────────────────────────
$allBackups = glob(BACKUP_DIR . '/backup_*.zip');
if ($allBackups) {
    usort($allBackups, fn($a, $b) => filemtime($b) - filemtime($a)); // neueste zuerst
    $toDelete = array_slice($allBackups, BACKUP_KEEP);
    foreach ($toDelete as $old) {
        @unlink($old);
        blog("Altes Backup gelöscht: " . basename($old));
    }
}

$remaining = count(glob(BACKUP_DIR . '/backup_*.zip'));
blog("=== Backup abgeschlossen ($remaining / " . BACKUP_KEEP . " Backups gespeichert) ===");
