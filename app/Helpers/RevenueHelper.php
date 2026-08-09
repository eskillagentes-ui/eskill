<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Fonte única de verdade para a definição de "receita" (Onda 2 / T1).
 *
 * Antes desta correção, Dashboard, Analytics e Vendas calculavam receita com
 * critérios diferentes: Dashboard somava TODOS os pedidos (inclusive
 * cancelados) em ml_orders, Analytics filtrava apenas status='paid', e Vendas
 * (OrderFinancialService) usava um conjunto mais amplo de status "pagos ou em
 * progresso de entrega". Isso gerava três números diferentes para o mesmo
 * período. A partir de agora, os três módulos usam exatamente esta lista de
 * status para reconhecer receita: pedidos pagos ou em qualquer etapa de
 * cumprimento pós-pagamento, excluindo cancelados/reembolsados.
 */
final class RevenueHelper
{
    /** @var list<string> */
    public const PAID_STATUSES = ['paid', 'delivered', 'confirmed', 'ready_to_ship', 'shipped', 'handling'];

    /**
     * Fragmento SQL pronto para uso em WHERE, ex.: "{$alias}status IN (...)".
     * Os valores são uma whitelist fixa (sem input externo), seguro para
     * concatenação direta.
     */
    public static function paidStatusesSql(string $columnExpr = 'status'): string
    {
        $quoted = array_map(
            static fn(string $status): string => "'" . $status . "'",
            self::PAID_STATUSES
        );

        return $columnExpr . ' IN (' . implode(', ', $quoted) . ')';
    }
}
