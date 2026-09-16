<?php
declare(strict_types=1);

namespace MatrixChat;

final class RateLimiter
{
    public static function hit(string $key, int $limit, int $windowSeconds): bool
    {
        $now = time();
        $bucketKey = '__rate_' . preg_replace('/[^a-z0-9_-]/i', '_', $key);
        $bucket = $_SESSION[$bucketKey] ?? ['start' => $now, 'count' => 0];

        if (!is_array($bucket) || ($now - (int)($bucket['start'] ?? 0)) >= $windowSeconds) {
            $bucket = ['start' => $now, 'count' => 0];
        }

        $bucket['count'] = (int)$bucket['count'] + 1;
        $_SESSION[$bucketKey] = $bucket;

        return $bucket['count'] <= $limit;
    }
}
