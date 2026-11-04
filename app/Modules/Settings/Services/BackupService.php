<?php

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Services\Backup\DatabaseDumper;
use App\Modules\Settings\Services\Backup\MysqlDumper;
use App\Modules\Settings\Services\Backup\SqliteDumper;
use App\Support\Errors\UserFacingException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Backups: one zip per run with the whole database (database.sql), the uploaded files
 * (logos, item images) and a manifest. Lives in storage/backups; old ones are pruned.
 */
class BackupService
{
    public const DIRECTORY = 'backups';

    public const PREFIX = 'rms-backup-';

    public function __construct(private readonly SettingsService $settings) {}

    public function directory(): string
    {
        $dir = storage_path(self::DIRECTORY);
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /** Take a backup now. Returns the zip's full path. */
    public function create(?string $note = null): string
    {
        $dir = $this->directory();
        $stamp = now()->format('Y-m-d-His');
        $zipPath = "{$dir}/".self::PREFIX."{$stamp}.zip";
        for ($n = 2; is_file($zipPath); $n++) { // two backups in the same second (e.g. a restore's safety copy)
            $zipPath = "{$dir}/".self::PREFIX."{$stamp}-{$n}.zip";
        }
        $sqlPath = "{$dir}/.tmp-{$stamp}-".getmypid().'.sql';

        $this->dumper()->dump($sqlPath);

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new UserFacingException("Could not create {$zipPath}");
        }
        $zip->addFile($sqlPath, 'database.sql');
        $zip->addFromString('manifest.json', json_encode([
            'app' => config('rms.product_name'),
            'version' => config('rms.version'),
            'created_at' => now()->toIso8601String(),
            'driver' => DB::connection()->getDriverName(),
            'note' => $note,
            'tables' => count($this->tables()),
        ], JSON_PRETTY_PRINT));

        $uploads = storage_path('app/public');
        if (is_dir($uploads)) {
            foreach (File::allFiles($uploads) as $file) {
                $zip->addFile($file->getPathname(), 'uploads/'.str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname()));
            }
        }
        // Lock every file in the zip: a lost USB stick must not expose customers, staff or sales (compliance assessment).
        $password = $this->password();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $zip->setEncryptionIndex($i, ZipArchive::EM_AES_256, $password);
        }
        $zip->close();
        @unlink($sqlPath);

        $this->prune();

        return $zipPath;
    }

    /**
     * Put a backup back. A safety backup of the current state is taken first.
     * Returns the path of that safety backup.
     */
    public function restore(string $zipPath, ?string $password = null): string
    {
        if (! is_file($zipPath)) {
            throw new UserFacingException("Backup not found: {$zipPath}");
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true || $zip->locateName('database.sql') === false) {
            throw new UserFacingException('This file is not a CorralBusiness backup (database.sql missing).');
        }
        // Locked backups need this server's backup password, or the one typed in for a backup from another server.
        // Older, unlocked backups open without one.
        $zip->setPassword(filled($password) ? $password : $this->password());
        if ($zip->getStream('database.sql') === false || $zip->getFromName('manifest.json') === false) {
            throw new UserFacingException('This backup is locked with a different backup password. Type the backup password of the server it came from.');
        }
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true) ?: [];
        $driver = DB::connection()->getDriverName();
        if (($manifest['driver'] ?? $driver) !== $driver) {
            throw new UserFacingException("This backup was made on {$manifest['driver']}; this server uses {$driver}.");
        }

        $entries = $this->safeEntries($zip);

        $safety = $this->create('before restore of '.basename($zipPath));

        $tmp = $this->directory().'/.restore-'.now()->format('His');
        File::ensureDirectoryExists($tmp);
        $zip->extractTo($tmp, $entries);
        $zip->close();

        $this->dumper()->restore("{$tmp}/database.sql");

        if (is_dir("{$tmp}/uploads")) {
            File::copyDirectory("{$tmp}/uploads", storage_path('app/public'));
        }
        File::deleteDirectory($tmp);

        $this->settings->forgetCache();
        Artisan::call('optimize:clear');

        return $safety;
    }

    /** The password backups are locked with: RMS_BACKUP_PASSWORD, or one derived from APP_KEY when that isn't set. */
    public function password(): string
    {
        $configured = (string) config('rms.backup.password');

        return $configured !== '' ? $configured : substr(hash_hmac('sha256', 'rms-backup-password', (string) config('app.key')), 0, 32);
    }

    public function isLocked(string $zipPath): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return false;
        }
        $stat = $zip->statName('database.sql');
        $zip->close();

        return ($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE;
    }

    /** @return array<int, array{name: string, path: string, size: int, created_at: Carbon}> newest first */
    public function list(): array
    {
        $files = [];
        foreach (File::files($this->directory()) as $file) {
            if (! str_starts_with($file->getFilename(), self::PREFIX) || $file->getExtension() !== 'zip') {
                continue;
            }
            $files[] = ['name' => $file->getFilename(), 'path' => $file->getPathname(), 'size' => $file->getSize(), 'created_at' => Carbon::createFromTimestamp($file->getMTime())];
        }
        usort($files, fn ($a, $b) => strcmp($b['name'], $a['name']));

        return $files;
    }

    public function latest(): ?array
    {
        return $this->list()[0] ?? null;
    }

    /** Delete backups older than the keep window (Settings key backup.keep_days, default 14). Always keeps the newest 3. */
    public function prune(): int
    {
        $keepDays = (int) $this->settings->get('backup.keep_days', 14);
        $deleted = 0;
        foreach (array_slice($this->list(), 3) as $backup) {
            if ($backup['created_at']->lt(now()->subDays($keepDays))) {
                File::delete($backup['path']);
                $deleted++;
            }
        }

        return $deleted;
    }

    /** Copy a backup to another folder (a USB stick). Returns the copied file's path. */
    public function copyTo(string $zipPath, string $folder): string
    {
        if (! is_dir($folder) || ! is_writable($folder)) {
            throw new UserFacingException("Cannot write to {$folder}. Is the USB stick plugged in?");
        }
        $target = rtrim($folder, '/').'/'.basename($zipPath);
        if (! copy($zipPath, $target)) {
            throw new UserFacingException("Copy to {$target} failed.");
        }

        return $target;
    }

    /**
     * Turn a backup name coming from a screen (download, restore, delete) into a path INSIDE storage/backups.
     * Only "rms-backup-<date>-<time>.zip" names are accepted; folders, "..", and paths elsewhere on the
     * server are never followed (security review H2).
     */
    public function resolve(string $name): string
    {
        $name = basename(trim($name));
        $pattern = '/^'.preg_quote(self::PREFIX, '/').'[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{6}(-[0-9]+)?\.zip$/';
        if (! preg_match($pattern, $name)) {
            throw new UserFacingException("Backup not found: {$name}");
        }

        $dir = realpath($this->directory());
        $path = realpath($dir.DIRECTORY_SEPARATOR.$name);
        if ($dir === false || $path === false || ! str_starts_with($path, $dir.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            throw new UserFacingException("Backup not found: {$name}");
        }

        return $path;
    }

    /** Files a backup may contain under uploads/ (logos, item images). */
    public const UPLOAD_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp']; // no SVG: it can carry a script (re-review)

    /**
     * Every name inside the zip is checked before anything is extracted (security review H3, "zip-slip"):
     * no "..", no absolute paths, no backslashes, and only database.sql, manifest.json and image files
     * under uploads/. A backup that carries anything else is refused as a whole.
     *
     * @return array<int, string>
     */
    private function safeEntries(ZipArchive $zip): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (! $this->isSafeEntry($name)) {
                throw new UserFacingException("This backup contains a file that does not belong in a backup ({$name}); it was not restored.");
            }
            $names[] = $name;
        }

        return $names;
    }

    public function isSafeEntry(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)) {
            return false;
        }
        foreach (explode('/', $name) as $part) {
            if ($part === '..') {
                return false;
            }
        }
        if (in_array($name, ['database.sql', 'manifest.json'], true)) {
            return true;
        }
        if (! str_starts_with($name, 'uploads/')) {
            return false;
        }
        if (str_ends_with($name, '/')) {
            return true; // a folder entry
        }

        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::UPLOAD_EXTENSIONS, true);
    }

    private function dumper(): DatabaseDumper
    {
        $connection = config('database.default');

        return match (DB::connection()->getDriverName()) {
            'sqlite' => new SqliteDumper($connection),
            default => new MysqlDumper($connection),
        };
    }

    /** @return array<int, string> */
    private function tables(): array
    {
        return array_map(fn ($t) => $t['name'], DB::connection()->getSchemaBuilder()->getTables());
    }
}
