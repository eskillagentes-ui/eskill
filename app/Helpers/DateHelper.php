<?php

declare(strict_types=1);

/**
 * Helper Global de Data/Hora — fonte única de formatação para exibição.
 *
 * Convenção do sistema (Onda 1 — timezone único):
 * - O fuso de exibição/negócio é sempre APP_TIMEZONE (America/Sao_Paulo),
 *   aplicado via App\Helpers\TimezoneHelper::applyFromEnv() no bootstrap.
 * - Colunas DATETIME sem offset (ex.: ml_accounts.token_expires_at) são
 *   gravadas como wall-clock nesse mesmo fuso.
 * - Datas vindas "ao vivo" da API do Mercado Livre trazem offset explícito
 *   (ex.: -04:00) e precisam ser convertidas para o fuso único antes de
 *   gravar ou exibir — usar normalize_datetime_to_app_tz() antes de persistir.
 */

use App\Helpers\TimezoneHelper;

if (!function_exists('format_datetime')) {
    /**
     * Formata uma data/hora para exibição no fuso único da aplicação.
     * Aceita valores já em wall-clock (sem offset) ou ISO8601 com offset.
     *
     * @param string|\DateTimeInterface|null $value
     */
    function format_datetime($value, string $format = 'd/m/Y H:i', string $empty = '—'): string
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return $empty;
        }

        try {
            $appTz = new \DateTimeZone(TimezoneHelper::current() ?: 'America/Sao_Paulo');
            $dt = $value instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($value)
                : new \DateTimeImmutable((string) $value);

            return $dt->setTimezone($appTz)->format($format);
        } catch (\Throwable $e) {
            return $empty;
        }
    }
}

if (!function_exists('normalize_datetime_to_app_tz')) {
    /**
     * Converte uma data (com ou sem offset explícito) para o formato de
     * persistência wall-clock no fuso único da aplicação (Y-m-d H:i:s).
     * Deve ser usada antes de gravar datas vindas de APIs externas (ex.:
     * Mercado Livre, que retorna offsets como -04:00) em colunas DATETIME,
     * para que o valor gravado já esteja no fuso único do sistema.
     *
     * @param string|\DateTimeInterface|null $value
     */
    function normalize_datetime_to_app_tz($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $appTz = new \DateTimeZone(TimezoneHelper::current() ?: 'America/Sao_Paulo');
            $dt = $value instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($value)
                : new \DateTimeImmutable((string) $value);

            return $dt->setTimezone($appTz)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('datetime_to_iso_with_offset')) {
    /**
     * Serializa uma data (assumida no fuso único da aplicação, sem offset)
     * para ISO 8601 com offset explícito, para ser consumida por JS sem
     * ambiguidade de fuso (evita `new Date()` reinterpretar a string no
     * fuso do navegador do visitante).
     *
     * @param string|\DateTimeInterface|null $value
     */
    function datetime_to_iso_with_offset($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $appTz = new \DateTimeZone(TimezoneHelper::current() ?: 'America/Sao_Paulo');
            $dt = $value instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($value)
                : new \DateTimeImmutable((string) $value, $appTz);

            return $dt->setTimezone($appTz)->format('c');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
