<?php

declare(strict_types=1);

namespace App\Services\Ads;

use Closure;

/**
 * Orquestra a coleta Ads sem acoplar o fluxo de decisão ao CLI.
 *
 * Alertas e tick são pós-efeitos permitidos somente quando a coleta termina
 * com ok=true. As dependências são callables para manter o contrato testável
 * sem banco, API ou serviços reais.
 */
final class AdsCollectCommand
{
    /** @var Closure(int, bool): array<string, mixed> */
    private Closure $collect;

    /** @var Closure(int): array<int, array<string, mixed>> */
    private Closure $alerts;

    /** @var Closure(int): array<string, mixed> */
    private Closure $tick;

    /**
     * @param callable(int, bool): array<string, mixed> $collect
     * @param callable(int): array<int, array<string, mixed>> $alerts
     * @param callable(int): array<string, mixed> $tick
     */
    public function __construct(callable $collect, callable $alerts, callable $tick)
    {
        $this->collect = Closure::fromCallable($collect);
        $this->alerts = Closure::fromCallable($alerts);
        $this->tick = Closure::fromCallable($tick);
    }

    /**
     * @return array{exit_code: int, result: array<string, mixed>}
     */
    public function execute(int $accountId, bool $history, bool $withTick): array
    {
        $result = ($this->collect)($accountId, $history);
        if (empty($result['ok'])) {
            $result['alerts'] = [];
            unset($result['tick']);

            return ['exit_code' => 1, 'result' => $result];
        }

        $result['alerts'] = ($this->alerts)($accountId);
        if ($withTick) {
            $result['tick'] = ($this->tick)($accountId);
        }

        return ['exit_code' => 0, 'result' => $result];
    }
}
