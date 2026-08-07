<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Dosya tabanlı basit rate limiter (Redis gerekmez).
 */
final class RateLimiter
{
    public function __construct(
        private readonly string $storageDir,
    ) {
        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0775, true) && !is_dir($this->storageDir)) {
            throw new RuntimeException('Rate limit klasörü oluşturulamadı');
        }
    }

    public function tooManyAttempts(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $data = $this->read($key);
        $now = time();

        if ($data === null) {
            return false;
        }

        if ($data['reset_at'] <= $now) {
            $this->clear($key);
            return false;
        }

        return $data['attempts'] >= $maxAttempts;
    }

    public function hit(string $key, int $decaySeconds): int
    {
        $now = time();
        $data = $this->read($key);

        if ($data === null || $data['reset_at'] <= $now) {
            $data = [
                'attempts' => 1,
                'reset_at' => $now + $decaySeconds,
            ];
        } else {
            $data['attempts']++;
        }

        $this->write($key, $data);

        return $data['attempts'];
    }

    public function clear(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function availableIn(string $key): int
    {
        $data = $this->read($key);
        if ($data === null) {
            return 0;
        }

        return max(0, $data['reset_at'] - time());
    }

    /**
     * @return array{attempts:int,reset_at:int}|null
     */
    private function read(string $key): ?array
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['attempts'], $decoded['reset_at'])) {
            return null;
        }

        return [
            'attempts' => (int) $decoded['attempts'],
            'reset_at' => (int) $decoded['reset_at'],
        ];
    }

    /**
     * @param array{attempts:int,reset_at:int} $data
     */
    private function write(string $key, array $data): void
    {
        file_put_contents(
            $this->path($key),
            (string) json_encode($data),
            LOCK_EX
        );
    }

    private function path(string $key): string
    {
        return $this->storageDir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }
}
