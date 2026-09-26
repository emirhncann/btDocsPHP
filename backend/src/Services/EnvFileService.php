<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Settings;
use PDO;
use PDOException;
use RuntimeException;

final class EnvFileService
{
    public const PASSWORD_MASK = '********';

    private const MANAGED_KEYS = [
        'LOGO_DB_HOST',
        'LOGO_DB_NAME',
        'LOGO_DB_USER',
        'LOGO_DB_PASS',
        'APP_DB_HOST',
        'APP_DB_NAME',
        'APP_DB_USER',
        'APP_DB_PASS',
        'DOC_STORAGE_ROOT',
        'PUBLIC_BASE_URL',
    ];

    private const APP_DB_KEYS = [
        'APP_DB_HOST',
        'APP_DB_NAME',
        'APP_DB_USER',
        'APP_DB_PASS',
    ];

    public function __construct(
        private readonly string $basePath,
    ) {
    }

    public function envPath(): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . '.env';
    }

    public function backupsDir(): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 'backups';
    }

    /**
     * @return array{
     *   logo_db_host: string,
     *   logo_db_name: string,
     *   logo_db_user: string,
     *   logo_db_pass: string,
     *   app_db_host: string,
     *   app_db_name: string,
     *   app_db_user: string,
     *   app_db_pass: string,
     *   doc_storage_root: string,
     *   public_base_url: string
     * }
     */
    public function readInfra(): array
    {
        $values = $this->readManagedValues();

        return [
            'logo_db_host' => $values['LOGO_DB_HOST'] ?? '',
            'logo_db_name' => $values['LOGO_DB_NAME'] ?? '',
            'logo_db_user' => $values['LOGO_DB_USER'] ?? '',
            'logo_db_pass' => self::PASSWORD_MASK,
            'app_db_host' => $values['APP_DB_HOST'] ?? '',
            'app_db_name' => $values['APP_DB_NAME'] ?? '',
            'app_db_user' => $values['APP_DB_USER'] ?? '',
            'app_db_pass' => self::PASSWORD_MASK,
            'doc_storage_root' => $values['DOC_STORAGE_ROOT'] ?? '',
            'public_base_url' => $values['PUBLIC_BASE_URL'] ?? '',
        ];
    }

    /**
     * Test için: maske/boş gelirse ilgili env şifresini döner.
     */
    public function resolvePasswordInput(?string $submitted, string $envKey = 'LOGO_DB_PASS'): string
    {
        $submitted = $submitted ?? '';
        if ($submitted === '' || $submitted === self::PASSWORD_MASK) {
            $values = $this->readManagedValues();

            return (string) ($values[$envKey] ?? '');
        }

        return $submitted;
    }

    /**
     * Geçici PDO — mevcut LogoDbConnection'a dokunmaz.
     *
     * @return array{success: bool, error?: string}
     */
    public function testLogoConnection(
        string $host,
        string $name,
        string $user,
        string $pass,
    ): array {
        return $this->testSqlServerConnection($host, $name, $user, $pass);
    }

    /**
     * APP_DB testi: bağlantı + Users tablosu (kaydet öncesi ile aynı kontroller).
     *
     * @return array{success: bool, error?: string}
     */
    public function testAppConnection(
        string $host,
        string $name,
        string $user,
        string $pass,
    ): array {
        try {
            $this->assertAppDbSafe($host, $name, $user, $pass);

            return ['success' => true];
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * APP_DB için zorunlu ön-doğrulama: bağlantı + Users tablosu.
     * Başarısızda InvalidArgumentException (422).
     */
    public function assertAppDbSafe(string $host, string $name, string $user, string $pass): void
    {
        $host = trim($host);
        $name = trim($name);
        if ($host === '' || $name === '') {
            throw new \InvalidArgumentException('app_db_host ve app_db_name zorunludur');
        }

        $dsn = sprintf(
            'sqlsrv:Server=%s;Database=%s;TrustServerCertificate=yes',
            $host,
            $name
        );

        $pdo = null;
        try {
            $uid = trim($user);
            $pdo = new PDO(
                $dsn,
                $uid === '' ? null : $uid,
                $uid === '' ? null : $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            $pdo->query('SELECT 1');
        } catch (PDOException $e) {
            throw new \InvalidArgumentException(
                'APP_DB bağlantı doğrulaması başarısız: ' . $e->getMessage()
            );
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(
                'APP_DB bağlantı doğrulaması başarısız: ' . $e->getMessage()
            );
        }

        try {
            $stmt = $pdo->query('SELECT COUNT(*) FROM dbo.Users');
            if ($stmt === false) {
                throw new \InvalidArgumentException(
                    'APP_DB doğrulaması başarısız: Users tablosu sorgulanamadı (yanlış veritabanı olabilir)'
                );
            }
            $stmt->fetchColumn();
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(
                'APP_DB doğrulaması başarısız: Users tablosu bulunamadı veya erişilemiyor (bu uygulama DB\'si gibi görünmüyor): '
                . $e->getMessage()
            );
        } finally {
            $pdo = null;
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   changed: list<string>,
     *   password_changed: bool,
     *   app_db_changed: bool,
     *   backup_path: string
     * }
     */
    public function updateInfra(array $input): array
    {
        $path = $this->envPath();
        if (!is_file($path)) {
            throw new RuntimeException('.env dosyası bulunamadı: ' . $path);
        }
        if (!is_writable($path)) {
            throw new RuntimeException('.env dosyası yazılabilir değil');
        }

        $current = $this->readManagedValues();

        $newLogoHost = $this->pickNonEmpty(
            trim((string) ($input['logo_db_host'] ?? '')),
            (string) ($current['LOGO_DB_HOST'] ?? '')
        );
        $newLogoName = $this->pickNonEmpty(
            trim((string) ($input['logo_db_name'] ?? '')),
            (string) ($current['LOGO_DB_NAME'] ?? '')
        );
        $newLogoUser = array_key_exists('logo_db_user', $input)
            ? trim((string) $input['logo_db_user'])
            : (string) ($current['LOGO_DB_USER'] ?? '');
        $logoPassRaw = is_string($input['logo_db_pass'] ?? null) ? (string) $input['logo_db_pass'] : '';
        $keepLogoPass = $logoPassRaw === '' || $logoPassRaw === self::PASSWORD_MASK;
        $newLogoPass = $keepLogoPass
            ? (string) ($current['LOGO_DB_PASS'] ?? '')
            : $logoPassRaw;

        $newAppHost = $this->pickNonEmpty(
            trim((string) ($input['app_db_host'] ?? '')),
            (string) ($current['APP_DB_HOST'] ?? '')
        );
        $newAppName = $this->pickNonEmpty(
            trim((string) ($input['app_db_name'] ?? '')),
            (string) ($current['APP_DB_NAME'] ?? '')
        );
        $newAppUser = array_key_exists('app_db_user', $input)
            ? trim((string) $input['app_db_user'])
            : (string) ($current['APP_DB_USER'] ?? '');
        $appPassRaw = is_string($input['app_db_pass'] ?? null) ? (string) $input['app_db_pass'] : '';
        $keepAppPass = $appPassRaw === '' || $appPassRaw === self::PASSWORD_MASK;
        $newAppPass = $keepAppPass
            ? (string) ($current['APP_DB_PASS'] ?? '')
            : $appPassRaw;

        $newRoot = $this->pickNonEmpty(
            trim((string) ($input['doc_storage_root'] ?? '')),
            (string) ($current['DOC_STORAGE_ROOT'] ?? '')
        );
        $newPublicBase = array_key_exists('public_base_url', $input)
            ? rtrim(trim((string) $input['public_base_url']), '/')
            : rtrim((string) ($current['PUBLIC_BASE_URL'] ?? ''), '/');

        if ($newLogoHost === '' || $newLogoName === '') {
            throw new \InvalidArgumentException('logo_db_host ve logo_db_name zorunludur');
        }
        if ($newAppHost === '' || $newAppName === '') {
            throw new \InvalidArgumentException('app_db_host ve app_db_name zorunludur');
        }
        if ($newRoot === '') {
            throw new \InvalidArgumentException('doc_storage_root zorunludur');
        }
        if ($newPublicBase !== '' && !preg_match('#^https?://[^\\s/]+#i', $newPublicBase)) {
            throw new \InvalidArgumentException(
                'public_base_url http(s)://host[:port] formatında olmalı (ör. http://192.168.1.10:8080)'
            );
        }

        $this->assertStorageRootWritable($newRoot);

        $updates = [
            'LOGO_DB_HOST' => $newLogoHost,
            'LOGO_DB_NAME' => $newLogoName,
            'LOGO_DB_USER' => $newLogoUser,
            'LOGO_DB_PASS' => $newLogoPass,
            'APP_DB_HOST' => $newAppHost,
            'APP_DB_NAME' => $newAppName,
            'APP_DB_USER' => $newAppUser,
            'APP_DB_PASS' => $newAppPass,
            'DOC_STORAGE_ROOT' => $newRoot,
            'PUBLIC_BASE_URL' => $newPublicBase,
        ];

        $appDbChanged = false;
        foreach (self::APP_DB_KEYS as $key) {
            if ((string) ($current[$key] ?? '') !== $updates[$key]) {
                $appDbChanged = true;
                break;
            }
        }

        // APP_DB değişiyorsa: .env'e dokunmadan önce zorunlu geçici bağlantı + Users kontrolü
        if ($appDbChanged) {
            $this->assertAppDbSafe($newAppHost, $newAppName, $newAppUser, $newAppPass);
        }

        $changed = [];
        $logoPasswordChanged = !$keepLogoPass && ((string) ($current['LOGO_DB_PASS'] ?? '') !== $newLogoPass);
        $appPasswordChanged = !$keepAppPass && ((string) ($current['APP_DB_PASS'] ?? '') !== $newAppPass);
        $passwordChanged = $logoPasswordChanged || $appPasswordChanged;

        foreach ($updates as $key => $value) {
            $old = (string) ($current[$key] ?? '');
            if ($old === $value) {
                continue;
            }
            if ($key === 'LOGO_DB_PASS' || $key === 'APP_DB_PASS') {
                continue;
            }
            $changed[] = strtolower($key);
        }

        $backupPath = $this->backupEnv();
        $this->writeManagedValues($updates);

        Settings::reload(dirname($this->basePath));

        return [
            'changed' => $changed,
            'password_changed' => $passwordChanged,
            'logo_password_changed' => $logoPasswordChanged,
            'app_password_changed' => $appPasswordChanged,
            'app_db_changed' => $appDbChanged,
            'backup_path' => $backupPath,
        ];
    }

    public function assertStorageRootWritable(string $root): void
    {
        $root = trim($root);
        if ($root === '') {
            throw new \InvalidArgumentException('doc_storage_root boş olamaz');
        }

        if (!is_dir($root)) {
            throw new \InvalidArgumentException('doc_storage_root yolu diskte yok: ' . $root);
        }

        if (!is_writable($root)) {
            throw new \InvalidArgumentException('doc_storage_root yazılabilir değil: ' . $root);
        }

        $probe = rtrim($root, "\\/") . DIRECTORY_SEPARATOR . '.btdocs_write_probe_' . bin2hex(random_bytes(4));
        $ok = @file_put_contents($probe, 'ok');
        if ($ok === false) {
            throw new \InvalidArgumentException('doc_storage_root yazma testi başarısız: ' . $root);
        }
        @unlink($probe);
    }

    /**
     * @return array{success: bool, error?: string}
     */
    private function testSqlServerConnection(
        string $host,
        string $name,
        string $user,
        string $pass,
    ): array {
        $host = trim($host);
        $name = trim($name);
        if ($host === '' || $name === '') {
            return ['success' => false, 'error' => 'Sunucu adresi ve veritabanı adı zorunludur'];
        }

        $dsn = sprintf(
            'sqlsrv:Server=%s;Database=%s;TrustServerCertificate=yes',
            $host,
            $name
        );

        try {
            $uid = trim($user);
            $pdo = new PDO(
                $dsn,
                $uid === '' ? null : $uid,
                $uid === '' ? null : $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            $pdo->query('SELECT 1');
            $pdo = null;

            return ['success' => true];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, string>
     */
    private function readManagedValues(): array
    {
        $path = $this->envPath();
        if (!is_file($path)) {
            return [];
        }

        $result = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('.env okunamadı');
        }

        foreach ($lines as $line) {
            $line = $this->stripBom($line);
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$rawKey, $rawVal] = explode('=', $line, 2);
            $key = trim($rawKey);
            if (!in_array($key, self::MANAGED_KEYS, true)) {
                continue;
            }
            $result[$key] = $this->unquoteValue(trim($rawVal));
        }

        foreach (self::MANAGED_KEYS as $key) {
            if (!array_key_exists($key, $result)) {
                $result[$key] = (string) (Settings::get($key, '') ?? '');
            }
        }

        return $result;
    }

    /**
     * Mevcut .env satırlarını koruyarak yalnızca verilen anahtarları günceller.
     * Yorumlar, boş satırlar ve yönetilmeyen anahtarlar aynen kalır.
     *
     * @param array<string, string> $updates
     */
    private function writeManagedValues(array $updates): void
    {
        $path = $this->envPath();
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('.env okunamadı');
        }

        // Dotenv UTF-8 BOM'u anahtar adına ekler → APP_ENV parse edilemez
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $eol = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split("/\r\n|\n|\r/", $content);
        if ($lines === false) {
            throw new RuntimeException('.env satırlara ayrılamadı');
        }

        // Dosya sonundaki tek boş satırı korumak için trailing empty'yi ayır
        $trailingEmpty = false;
        if ($lines !== [] && end($lines) === '') {
            $trailingEmpty = true;
            array_pop($lines);
        }

        $seen = [];
        foreach ($lines as $i => $line) {
            $work = $this->stripBom($line);
            $trimmed = trim($work);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($work, '=')) {
                continue;
            }
            [$rawKey] = explode('=', $work, 2);
            $key = trim($rawKey);
            if (!array_key_exists($key, $updates)) {
                continue;
            }
            $lines[$i] = $key . '=' . $this->formatValue($updates[$key]);
            $seen[$key] = true;
        }

        foreach ($updates as $key => $value) {
            if (!isset($seen[$key])) {
                $lines[] = $key . '=' . $this->formatValue($value);
            }
        }

        $out = implode($eol, $lines);
        if ($trailingEmpty || !str_ends_with($out, $eol)) {
            $out .= $eol;
        }

        $this->assertEnvParsable($out);

        if (file_put_contents($path, $out) === false) {
            throw new RuntimeException('.env yazılamadı');
        }
    }

    private function backupEnv(): string
    {
        $path = $this->envPath();
        $dir = $this->backupsDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('config/backups klasörü oluşturulamadı');
        }

        $backup = $dir . DIRECTORY_SEPARATOR . '.env.backup-' . date('Ymd-His');
        if (!@copy($path, $backup)) {
            throw new RuntimeException('.env yedeği alınamadı');
        }

        return $backup;
    }

    /**
     * Dotenv çift tırnaklı değerde \ kaçışı zorunlu (Z:\btdocs → "Z:\\btdocs").
     */
    private function formatValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Windows yolları ve boşluk/özel karakter → çift tırnak + escape
        if (preg_match('/[\s#"\'\\\\]|[\r\n]/', $value) === 1) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

            return '"' . $escaped . '"';
        }

        return $value;
    }

    private function unquoteValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $len = strlen($value);
        if ($len >= 2) {
            $q = $value[0];
            if (($q === '"' || $q === "'") && $value[$len - 1] === $q) {
                $inner = substr($value, 1, -1);
                if ($q === '"') {
                    return stripcslashes($inner);
                }

                return $inner;
            }
        }

        return $value;
    }

    private function stripBom(string $line): string
    {
        if (str_starts_with($line, "\xEF\xBB\xBF")) {
            return substr($line, 3);
        }

        return $line;
    }

    /** Boş gelen istek alanı mevcut .env değerini ezmesin. */
    private function pickNonEmpty(string $incoming, string $fallback): string
    {
        return $incoming !== '' ? $incoming : $fallback;
    }

    /**
     * Yazmadan önce Dotenv'in dosyayı parse edebildiğini doğrula —
     * aksi halde Settings::reload tüm env'i düşürür, UI alanları boş kalır.
     */
    private function assertEnvParsable(string $content): void
    {
        try {
            (new \Dotenv\Parser\Parser())->parse($content);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(
                '.env değeri Dotenv tarafından parse edilemiyor (Windows yollarında ters eğik çizgiyi kaçırın): '
                . $e->getMessage()
            );
        }
    }
}
