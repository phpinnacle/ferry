<?php

namespace PHPinnacle\Ferry\Support;

use PDOException;
use Throwable;

class ConnectionErrorFormatter
{
    private const array STATES = [
        '28000' => 'denied',
        '28P01' => 'denied',
        '3D000' => 'unknown_database',
        '3F000' => 'unknown_schema',
    ];

    private const array CODES = [
        1044 => 'denied',
        1045 => 'denied',
        1049 => 'unknown_database',
        2002 => 'unreachable',
        2003 => 'unreachable',
        2005 => 'unreachable',
        2006 => 'unreachable',
        4060 => 'unknown_database',
        18456 => 'denied',
    ];

    private const array PATTERNS = [
        '/authentication failed|no pg_hba\.conf entry|role ".+" does not exist/i' => 'denied',
        '/database ".+" does not exist/i' => 'unknown_database',
        '/schema ".+" does not exist/i' => 'unknown_schema',
        '/timeout expired|connection refused|could not translate host name|no route to host/i' => 'unreachable',
    ];

    private const array CONNECTION_STATES = [
        '08000' => 'unreachable',
        '08001' => 'unreachable',
        '08003' => 'unreachable',
        '08004' => 'unreachable',
        '08006' => 'unreachable',
    ];

    public static function format(Throwable $exception): string
    {
        $state = self::state($exception);

        $reason =
            self::STATES[$state] ?? self::CODES[self::code($exception)] ?? self::pattern(
                $exception->getMessage(),
            ) ?? self::CONNECTION_STATES[$state] ?? 'unknown';

        return self::message($reason, $state);
    }

    public static function stale(): string
    {
        return self::message('stale');
    }

    private static function code(Throwable $exception): int
    {
        if (!$exception instanceof PDOException) {
            return 0;
        }

        return ($exception->errorInfo[1] ?? null) !== null ? (int) $exception->errorInfo[1] : 0;
    }

    private static function message(string $reason, string $state = ''): string
    {
        return __('phpinnacle-ferry::resources.connection.errors.' . $reason, ['state' => $state]);
    }

    private static function pattern(string $message): ?string
    {
        foreach (self::PATTERNS as $pattern => $reason) {
            if (preg_match($pattern, $message) === 1) {
                return $reason;
            }
        }

        return null;
    }

    private static function state(Throwable $exception): string
    {
        if ($exception instanceof PDOException && ($exception->errorInfo[0] ?? null) !== null) {
            return (string) $exception->errorInfo[0];
        }

        $code = $exception->getCode();

        return is_string($code) && $code !== '' ? $code : 'HY000';
    }
}
