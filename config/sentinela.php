<?php

declare(strict_types=1);

/**
 * Limiares e metadados dos 11 riscos do Sentinela.
 * NF pendente permanece nd até definição do emissor/ERP.
 *
 * @return array<string, array{
 *   label: string,
 *   limit: float|null,
 *   yellow_at: float|null,
 *   red_at: float|null,
 *   unit: string,
 *   higher_is_worse: bool,
 *   criterion?: string
 * }>
 */
return [
    'reputacao' => [
        'label' => 'Reputação',
        'limit' => null,
        'yellow_at' => null,
        'red_at' => null,
        'unit' => 'cor',
        'higher_is_worse' => true,
        'criterion' => 'Amarelo em queda de nível; vermelho se cor vermelha/perda de venda',
    ],
    'reclamacoes' => [
        'label' => 'Reclamações',
        'limit' => 2.0,
        'yellow_at' => 1.0,
        'red_at' => 1.6,
        'unit' => '%',
        'higher_is_worse' => true,
        'criterion' => '50% do limite ML (~2%) = 1%',
    ],
    'atrasos' => [
        'label' => 'Atrasos de despacho',
        'limit' => 15.0,
        'yellow_at' => 7.0,
        'red_at' => 12.0,
        'unit' => '%',
        'higher_is_worse' => true,
        'criterion' => '50% do limite ML (~15%) = 7%',
    ],
    'cancelamentos' => [
        'label' => 'Cancelamentos',
        'limit' => 2.5,
        'yellow_at' => 1.0,
        'red_at' => 2.0,
        'unit' => '%',
        'higher_is_worse' => true,
        'criterion' => '50% do limite ML (~2,5%) = 1%',
    ],
    'moderacao' => [
        'label' => 'Moderação de anúncio',
        'limit' => 3.0,
        'yellow_at' => 1.0,
        'red_at' => 3.0,
        'unit' => 'itens',
        'higher_is_worse' => true,
        'criterion' => '≥1 under_review = amarelo; ≥3 ou top-volume = vermelho',
    ],
    'catalogo' => [
        'label' => 'Bloqueio de catálogo',
        'limit' => 5.0,
        'yellow_at' => 1.0,
        'red_at' => 5.0,
        'unit' => '% itens',
        'higher_is_worse' => true,
        'criterion' => 'Item top-volume afetado = amarelo; >5% catálogo = vermelho',
    ],
    'chargeback' => [
        'label' => 'Chargeback/disputa',
        'limit' => 1.0,
        'yellow_at' => 1.0,
        'red_at' => 1.0,
        'unit' => 'abertas',
        'higher_is_worse' => true,
        'criterion' => 'Qualquer disputa aberta = amarelo; prazo <48h = vermelho',
    ],
    'oauth' => [
        'label' => 'Saúde OAuth',
        'limit' => 2.0,
        'yellow_at' => 1.0,
        'red_at' => 2.0,
        'unit' => 'falhas',
        'higher_is_worse' => true,
        'criterion' => 'Expira <2h ou 1 falha = amarelo; ≥2 falhas/disconnected = vermelho',
    ],
    'rate_limit' => [
        'label' => 'Rate limit (429)',
        'limit' => 3.0,
        'yellow_at' => 1.0,
        'red_at' => 3.0,
        'unit' => '429/1h',
        'higher_is_worse' => true,
        'criterion' => '1× em 5min = amarelo; 3× em 1h = vermelho',
    ],
    'nf_pendente' => [
        'label' => 'NF pendente',
        'limit' => null,
        'yellow_at' => null,
        'red_at' => null,
        'unit' => 'n/d',
        'higher_is_worse' => true,
        'criterion' => 'Aguardando definição do emissor/ERP — fora de escopo',
    ],
    'queda_vendas' => [
        'label' => 'Queda brusca de vendas',
        'limit' => 50.0,
        'yellow_at' => 25.0,
        'red_at' => 50.0,
        'unit' => '% queda',
        'higher_is_worse' => true,
        'criterion' => '≥25% vs baseline 28d = amarelo; ≥50% = vermelho; dia -40% imediato',
    ],
];
