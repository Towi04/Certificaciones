<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Límite simple de intentos de login / recuperación (archivo en storage).
 */
final class LoginThrottle
{
    private string $dir;
    private int $maxAttempts;
    private int $windowSeconds;

    public function __construct(int $maxAttempts = 8, int $windowSeconds = 900)
    {
        $this->maxAttempts = max(3, $maxAttempts);
        $this->windowSeconds = max(60, $windowSeconds);
        $this->dir = BASE_PATH . '/storage/logs/throttle';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0750, true);
        }
    }

    public function tooManyAttempts(string $bucket): bool
    {
        $data = $this->read($bucket);
        $now = time();
        $window = $this->windowSeconds;
        $data['attempts'] = array_values(array_filter(
            $data['attempts'],
            static fn (int $t): bool => ($now - $t) < $window
        ));
        $this->write($bucket, $data);

        return count($data['attempts']) >= $this->maxAttempts;
    }

    public function hit(string $bucket): void
    {
        $data = $this->read($bucket);
        $now = time();
        $window = $this->windowSeconds;
        $data['attempts'] = array_values(array_filter(
            $data['attempts'],
            static fn (int $t): bool => ($now - $t) < $window
        ));
        $data['attempts'][] = $now;
        $this->write($bucket, $data);
    }

    public function clear(string $bucket): void
    {
        $path = $this->path($bucket);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function clientKey(string $action, string $email = ''): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $email = strtolower(trim($email));

        return $action . '|' . $ip . '|' . $email;
    }

    /** @return array{attempts:list<int>} */
    private function read(string $bucket): array
    {
        $path = $this->path($bucket);
        if (!is_file($path)) {
            return ['attempts' => []];
        }
        $raw = @file_get_contents($path);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($json) || !isset($json['attempts']) || !is_array($json['attempts'])) {
            return ['attempts' => []];
        }
        $attempts = [];
        foreach ($json['attempts'] as $t) {
            if (is_int($t) || (is_string($t) && ctype_digit($t))) {
                $attempts[] = (int) $t;
            }
        }

        return ['attempts' => $attempts];
    }

    /** @param array{attempts:list<int>} $data */
    private function write(string $bucket, array $data): void
    {
        $path = $this->path($bucket);
        @file_put_contents($path, json_encode($data), LOCK_EX);
        @chmod($path, 0640);
    }

    private function path(string $bucket): string
    {
        return $this->dir . '/' . hash('sha256', $bucket) . '.json';
    }
}
