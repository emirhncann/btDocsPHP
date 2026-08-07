<?php

declare(strict_types=1);

/**
 * İlk admin kullanıcısını oluşturur.
 *
 * Kullanım:
 *   php seed_admin.php --username=admin --password=GucluSifre123 --full-name="Sistem Admin"
 */

use App\Config\Settings;
use App\Repositories\UserRepository;
use App\Services\AppDbConnection;

require __DIR__ . '/vendor/autoload.php';

$options = getopt('', ['username:', 'password:', 'full-name::', 'role::']);

$username = isset($options['username']) ? trim((string) $options['username']) : '';
$password = isset($options['password']) ? (string) $options['password'] : '';
$fullName = isset($options['full-name']) ? trim((string) $options['full-name']) : 'Sistem Admin';
$role = isset($options['role']) ? trim((string) $options['role']) : 'admin';

if ($username === '' || $password === '') {
    fwrite(STDERR, "Kullanım: php seed_admin.php --username=admin --password=Sifre [--full-name=\"Ad Soyad\"] [--role=admin]\n");
    exit(1);
}

$allowedRoles = ['admin', 'user', 'readonly'];
if (!in_array($role, $allowedRoles, true)) {
    fwrite(STDERR, "Geçersiz role. İzin verilenler: " . implode(', ', $allowedRoles) . "\n");
    exit(1);
}

if (strlen($password) < 6) {
    fwrite(STDERR, "Şifre en az 6 karakter olmalı.\n");
    exit(1);
}

Settings::load(__DIR__);

try {
    $users = new UserRepository(new AppDbConnection());
    $existing = $users->findByUsername($username);

    if ($existing !== null) {
        fwrite(STDERR, "Kullanıcı zaten mevcut: {$username} (id={$existing['id']})\n");
        exit(1);
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    if ($hash === false) {
        throw new RuntimeException('password_hash başarısız');
    }

    $id = $users->create($username, $hash, $fullName, $role);
    echo "Admin kullanıcı oluşturuldu: id={$id}, username={$username}, role={$role}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Hata: ' . $e->getMessage() . "\n");
    exit(1);
}
