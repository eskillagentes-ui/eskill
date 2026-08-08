<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Aplica APP_TIMEZONE no PHP e alinha a sessão MySQL (SET time_zone).
 */
final class TimezoneHelper
{
    private static bool $applied = false;

    private static string $appliedTimezone = '';

    public static function applyFromEnv(): string
    {
        if (self::$applied) {
            return self::$appliedTimezone;
        }

        $desired = self::resolveDesiredTimezone();
        date_default_timezone_set($desired);
        self::$applied = true;
        self::$appliedTimezone = $desired;

        return $desired;
    }

    public static function current(): string
    {
        return self::$applied ? self::$appliedTimezone : date_default_timezone_get();
    }

    /**
     * Literal OFFSET para SET time_zone (não depende de tabelas de timezone do MySQL).
     */
    public static function mysqlOffsetLiteral(?string $timezone = null): string
    {
        $tzName = $timezone ?? date_default_timezone_get();
        $tz = new DateTimeZone($tzName);
        $offset = $tz->getOffset(new DateTimeImmutable('now', $tz));
        $sign = $offset >= 0 ? '+' : '-';
        $abs = abs($offset);
        $hours = intdiv($abs, 3600);
        $minutes = intdiv($abs % 3600, 60);

        return sprintf('%s%02d:%02d', $sign, $hours, $minutes);
    }

    public static function applyMysqlSession(\PDO $pdo): void
    {
        $literal = self::mysqlOffsetLiteral();
        $pdo->exec("SET time_zone = '{$literal}'");
    }

    /**
     * Reescreve DATETIME wall-clock de $fromTz para $toTz preservando o instante absoluto.
     */
    public static function rewriteWallClock(string $wall, string $fromTz, string $toTz): ?string
    {
        if (trim($wall) === '' || $wall === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($wall, new DateTimeZone($fromTz));
            return $dt->setTimezone(new DateTimeZone($toTz))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Migra token_expires_at / last_refresh_at uma vez (idempotente via state file).
     *
     * @return array{migrated: bool, rows: int, from: string, to: string, reason?: string}
     */
    public static function migrateTokenDatetimesOnce(\PDO $pdo, string $fromTz, string $toTz): array
    {
        $statePath = dirname(__DIR__, 2) . '/storage/app_timezone_state.json';
        $state = [];
        if (is_file($statePath)) {
            $decoded = json_decode((string) file_get_contents($statePath), true);
            if (is_array($decoded)) {
                $state = $decoded;
            }
        }

        if (($state['tz'] ?? null) === $toTz && ($state['tokens_migrated'] ?? false) === true) {
            return [
                'migrated' => false,
                'rows' => 0,
                'from' => $fromTz,
                'to' => $toTz,
                'reason' => 'already_migrated',
            ];
        }

        $stmt = $pdo->query(
            "SELECT id, token_expires_at, last_refresh_at
             FROM ml_accounts
             WHERE token_expires_at IS NOT NULL
                OR last_refresh_at IS NOT NULL"
        );
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        $upd = $pdo->prepare(
            'UPDATE ml_accounts
             SET token_expires_at = :expires_at,
                 last_refresh_at = :last_refresh_at
             WHERE id = :id'
        );

        $changed = 0;
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $expiresRaw = isset($row['token_expires_at']) ? (string)$row['token_expires_at'] : '';
            $refreshRaw = isset($row['last_refresh_at']) ? (string)$row['last_refresh_at'] : '';

            $expires = $expiresRaw !== ''
                ? (self::rewriteWallClock($expiresRaw, $fromTz, $toTz) ?? $expiresRaw)
                : null;
            $lastRefresh = $refreshRaw !== ''
                ? (self::rewriteWallClock($refreshRaw, $fromTz, $toTz) ?? $refreshRaw)
                : null;

            if ($expires === $expiresRaw && $lastRefresh === $refreshRaw) {
                continue;
            }

            $upd->execute([
                'expires_at' => $expires,
                'last_refresh_at' => $lastRefresh,
                'id' => $id,
            ]);
            $changed++;
        }

        $dir = dirname($statePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents(
            $statePath,
            (string) json_encode([
                'tz' => $toTz,
                'previous' => $fromTz,
                'tokens_migrated' => true,
                'rows' => $changed,
                'updated_at' => date('c'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        return [
            'migrated' => true,
            'rows' => $changed,
            'from' => $fromTz,
            'to' => $toTz,
        ];
    }

    private static function resolveDesiredTimezone(): string
    {
        $raw = trim((string)($_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: ''));
        if ($raw === '') {
            return 'UTC';
        }

        if (!in_array($raw, timezone_identifiers_list(), true)) {
            return 'UTC';
        }

        return $raw;
    }
}
