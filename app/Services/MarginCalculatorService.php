<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

/**
 * Margin Calculator Service
 *
 * Cálculo preciso de margens considerando:
 * - Comissão do Mercado Livre (por categoria)
 * - Impostos (Simples Nacional, Lucro Presumido, MEI)
 * - Custo de Ads (ACOS médio)
 * - Frete grátis subsidiado
 * - Embalagem e custos extras
 *
 * @package App\Services
 */
class MarginCalculatorService
{
    private PDO $db;
    private ?int $accountId;
    private array $categoryFeesCache = [];

    // Regimes tributários
    public const REGIME_SIMPLES = 'simples';
    public const REGIME_PRESUMIDO = 'presumido';
    public const REGIME_MEI = 'mei';

    // Alíquotas padrão
    private const TAX_RATES = [
        self::REGIME_MEI => 0.05,        // 5% DAS
        self::REGIME_SIMPLES => 0.09,    // ~9% média Simples
        self::REGIME_PRESUMIDO => 0.1133 // ~11.33% (IRPJ+CSLL+PIS+COFINS)
    ];

    public function __construct(?int $accountId = null)
    {
        $this->accountId = $accountId;
        $this->db = Database::getInstance();
    }

    /**
     * Calcula a margem real de um produto considerando todos os custos
     *
     * @param float $precoVenda Preço de venda no ML
     * @param array $custos Array com custos do produto
     * @return array Breakdown completo da margem
     */
    public function calcularMargem(float $precoVenda, array $custos): array
    {
        // Validação
        if ($precoVenda <= 0) {
            return $this->errorResponse('Preço de venda inválido');
        }

        // Extrair custos com valores padrão
        $custoProducao = (float)($custos['custo_producao'] ?? 0);
        $custoEmbalagem = (float)($custos['custo_embalagem'] ?? 0);
        $custoEtiqueta = (float)($custos['custo_etiqueta'] ?? 0);
        $custoFreteEntrada = (float)($custos['custo_frete_entrada'] ?? 0);

        // Taxas (em percentual)
        $taxaComissaoML = (float)($custos['taxa_comissao_ml'] ?? 16); // 16% padrão
        $taxaImposto = (float)($custos['taxa_imposto'] ?? 9);         // Simples padrão
        $acosMedio = (float)($custos['acos_medio'] ?? 0);

        // Frete grátis subsidiado pelo vendedor
        $custoFreteGratis = (float)($custos['custo_frete_gratis'] ?? 0);

        // Cálculos em R$
        $comissaoML = $precoVenda * ($taxaComissaoML / 100);
        $imposto = $precoVenda * ($taxaImposto / 100);
        $custoAds = $precoVenda * ($acosMedio / 100);

        // Custo fixo total (não varia com preço)
        $custoFixo = $custoProducao + $custoEmbalagem + $custoEtiqueta + $custoFreteEntrada + $custoFreteGratis;

        // Custo variável total (% do preço)
        $custoVariavel = $comissaoML + $imposto + $custoAds;

        // Custo total
        $custoTotal = $custoFixo + $custoVariavel;

        // Lucro e margem
        $lucroUnitario = $precoVenda - $custoTotal;
        $margemReal = $precoVenda > 0 ? ($lucroUnitario / $precoVenda) * 100 : 0;
        $margemSobreCusto = $custoFixo > 0 ? ($lucroUnitario / $custoFixo) * 100 : 0;

        // Indicador de saúde
        $indicador = $this->getIndicadorSaude($margemReal);

        return [
            'success' => true,
            'preco_venda' => round($precoVenda, 2),
            'lucro_unitario' => round($lucroUnitario, 2),
            'margem_real' => round($margemReal, 2),
            'margem_sobre_custo' => round($margemSobreCusto, 2),
            'indicador' => $indicador,
            'breakdown' => [
                'custos_fixos' => [
                    'producao' => round($custoProducao, 2),
                    'embalagem' => round($custoEmbalagem, 2),
                    'etiqueta' => round($custoEtiqueta, 2),
                    'frete_entrada' => round($custoFreteEntrada, 2),
                    'frete_gratis' => round($custoFreteGratis, 2),
                    'total_fixo' => round($custoFixo, 2)
                ],
                'custos_variaveis' => [
                    'comissao_ml' => round($comissaoML, 2),
                    'comissao_ml_percent' => $taxaComissaoML,
                    'imposto' => round($imposto, 2),
                    'imposto_percent' => $taxaImposto,
                    'ads' => round($custoAds, 2),
                    'ads_percent' => $acosMedio,
                    'total_variavel' => round($custoVariavel, 2)
                ],
                'custo_total' => round($custoTotal, 2)
            ]
        ];
    }

    /**
     * Calcula o preço mínimo para atingir uma margem alvo
     */
    public function calcularPrecoMinimo(array $custos, float $margemAlvo = 10): array
    {
        $custoProducao = (float)($custos['custo_producao'] ?? 0);
        $custoEmbalagem = (float)($custos['custo_embalagem'] ?? 0);
        $custoEtiqueta = (float)($custos['custo_etiqueta'] ?? 0);
        $custoFreteEntrada = (float)($custos['custo_frete_entrada'] ?? 0);
        $custoFreteGratis = (float)($custos['custo_frete_gratis'] ?? 0);

        $taxaComissaoML = (float)($custos['taxa_comissao_ml'] ?? 16) / 100;
        $taxaImposto = (float)($custos['taxa_imposto'] ?? 9) / 100;
        $acosMedio = (float)($custos['acos_medio'] ?? 0) / 100;

        $custoFixo = $custoProducao + $custoEmbalagem + $custoEtiqueta + $custoFreteEntrada + $custoFreteGratis;
        $taxaVariavelTotal = $taxaComissaoML + $taxaImposto + $acosMedio;
        $margemDecimal = $margemAlvo / 100;

        // Fórmula: Preço = CustoFixo / (1 - TaxaVariável - Margem)
        $divisor = 1 - $taxaVariavelTotal - $margemDecimal;

        if ($divisor <= 0) {
            return $this->errorResponse('Margem impossível de atingir com os custos atuais');
        }

        $precoMinimo = $custoFixo / $divisor;

        return [
            'success' => true,
            'preco_minimo' => round($precoMinimo, 2),
            'margem_alvo' => $margemAlvo,
            'custo_fixo' => round($custoFixo, 2),
            'taxa_variavel_total' => round($taxaVariavelTotal * 100, 2),
            'verificacao' => $this->calcularMargem($precoMinimo, $custos)
        ];
    }

    /**
     * Simula desconto e retorna impacto na margem
     */
    public function simularDesconto(float $precoOriginal, float $descontoPercent, array $custos): array
    {
        if ($descontoPercent < 0 || $descontoPercent > 100) {
            return $this->errorResponse('Desconto deve estar entre 0 e 100%');
        }

        $precoPromocional = $precoOriginal * (1 - $descontoPercent / 100);

        $margemOriginal = $this->calcularMargem($precoOriginal, $custos);
        $margemPromocao = $this->calcularMargem($precoPromocional, $custos);

        // Calcular desconto máximo seguro (margem mínima 5%)
        $descontoMaximo = $this->calcularDescontoMaximoSeguro($precoOriginal, $custos, 5);

        return [
            'success' => true,
            'preco_original' => round($precoOriginal, 2),
            'desconto_percent' => $descontoPercent,
            'preco_promocional' => round($precoPromocional, 2),
            'economia_cliente' => round($precoOriginal - $precoPromocional, 2),
            'margem_original' => $margemOriginal['margem_real'] ?? 0,
            'margem_promocao' => $margemPromocao['margem_real'] ?? 0,
            'lucro_original' => $margemOriginal['lucro_unitario'] ?? 0,
            'lucro_promocao' => $margemPromocao['lucro_unitario'] ?? 0,
            'viavel' => ($margemPromocao['margem_real'] ?? 0) >= 5,
            'desconto_maximo_seguro' => $descontoMaximo,
            'cenarios' => $this->gerarCenariosDesconto($precoOriginal, $custos)
        ];
    }

    /**
     * Gera cenários de desconto (5%, 10%, 15%, 20%, 25%, 30%)
     */
    private function gerarCenariosDesconto(float $precoOriginal, array $custos): array
    {
        $cenarios = [];
        $descontos = [5, 10, 15, 20, 25, 30];

        foreach ($descontos as $desc) {
            $precoDesc = $precoOriginal * (1 - $desc / 100);
            $margem = $this->calcularMargem($precoDesc, $custos);

            $cenarios[] = [
                'desconto' => $desc,
                'preco' => round($precoDesc, 2),
                'margem' => $margem['margem_real'] ?? 0,
                'lucro' => $margem['lucro_unitario'] ?? 0,
                'viavel' => ($margem['margem_real'] ?? 0) >= 5,
                'indicador' => $margem['indicador'] ?? 'vermelho'
            ];
        }

        return $cenarios;
    }

    /**
     * Calcula o desconto máximo mantendo margem mínima
     */
    public function calcularDescontoMaximoSeguro(float $precoOriginal, array $custos, float $margemMinima = 5): float
    {
        $resultado = $this->calcularPrecoMinimo($custos, $margemMinima);

        if (!$resultado['success']) {
            return 0;
        }

        $precoMinimo = $resultado['preco_minimo'];

        if ($precoMinimo >= $precoOriginal) {
            return 0;
        }

        $descontoMaximo = (($precoOriginal - $precoMinimo) / $precoOriginal) * 100;

        return round(max(0, $descontoMaximo), 2);
    }

    /**
     * Analisa impacto de aumento de preço no ranking
     */
    public function analisarImpactoRanking(float $precoAtual, float $precoNovo): array
    {
        if ($precoAtual <= 0) {
            return $this->errorResponse('Preço atual inválido');
        }

        $variacao = (($precoNovo - $precoAtual) / $precoAtual) * 100;

        // Limites de impacto no algoritmo do ML
        $alerta = 'verde';
        $mensagem = 'Alteração segura para o ranking';
        $recomendacao = null;

        if ($variacao > 15) {
            $alerta = 'vermelho';
            $mensagem = 'ALERTA: Aumento acima de 15% pode causar queda severa no ranking';
            $recomendacao = 'Considere aumentar gradualmente em etapas de 5-8%';
        } elseif ($variacao > 12) {
            $alerta = 'vermelho';
            $mensagem = 'RISCO: Aumento entre 12-15% frequentemente penaliza o produto';
            $recomendacao = 'Recomendado limitar a 10% por vez';
        } elseif ($variacao > 8) {
            $alerta = 'amarelo';
            $mensagem = 'ATENÇÃO: Aumento entre 8-12% pode impactar moderadamente';
            $recomendacao = 'Monitore vendas nos próximos 7 dias';
        } elseif ($variacao < -20) {
            $alerta = 'amarelo';
            $mensagem = 'ATENÇÃO: Desconto acima de 20% pode indicar produto problemático';
            $recomendacao = 'Verifique se o desconto é sustentável';
        }

        // Calcular preço máximo seguro
        $precoMaximoSeguro = $precoAtual * 1.08;

        return [
            'success' => true,
            'preco_atual' => round($precoAtual, 2),
            'preco_novo' => round($precoNovo, 2),
            'variacao_percent' => round($variacao, 2),
            'alerta' => $alerta,
            'mensagem' => $mensagem,
            'recomendacao' => $recomendacao,
            'preco_maximo_seguro' => round($precoMaximoSeguro, 2),
            'limites' => [
                'seguro' => 8,
                'moderado' => 12,
                'alto_risco' => 15
            ]
        ];
    }

    /**
     * Busca taxa de comissão por categoria
     */
    public function getTaxaComissaoCategoria(string $categoryId, string $tipoAnuncio = 'classico'): float
    {
        if (isset($this->categoryFeesCache[$categoryId])) {
            $fee = $this->categoryFeesCache[$categoryId];
            return $tipoAnuncio === 'premium' ? $fee['taxa_premium'] : $fee['taxa_classico'];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT taxa_classico, taxa_premium
                FROM ml_category_fees
                WHERE category_id = :category_id
            ");
            $stmt->execute(['category_id' => $categoryId]);
            $fee = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($fee) {
                $this->categoryFeesCache[$categoryId] = $fee;
                return $tipoAnuncio === 'premium' ? $fee['taxa_premium'] : $fee['taxa_classico'];
            }
        } catch (\Throwable $e) {
            // Silently fail and return default
        }

        // Taxa padrão se categoria não encontrada
        return $tipoAnuncio === 'premium' ? 19.0 : 16.0;
    }

    /**
     * Calcula imposto baseado no regime tributário
     */
    public function calcularImposto(float $valor, string $regime = self::REGIME_SIMPLES): float
    {
        $taxa = self::TAX_RATES[$regime] ?? self::TAX_RATES[self::REGIME_SIMPLES];
        return $valor * $taxa;
    }

    /**
     * Salva custos de produto no banco
     */
    public function salvarCustosProduto(string $itemId, array $custos): array
    {
        if (!$this->accountId) {
            return $this->errorResponse('Account ID necessário');
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO product_costs (
                    account_id, item_id, sku, custo_producao, custo_embalagem,
                    custo_etiqueta, custo_frete_entrada, taxa_comissao_ml,
                    taxa_imposto, acos_medio, custo_frete_gratis,
                    margem_minima, margem_alvo
                ) VALUES (
                    :account_id, :item_id, :sku, :custo_producao, :custo_embalagem,
                    :custo_etiqueta, :custo_frete_entrada, :taxa_comissao_ml,
                    :taxa_imposto, :acos_medio, :custo_frete_gratis,
                    :margem_minima, :margem_alvo
                )
                ON DUPLICATE KEY UPDATE
                    sku = VALUES(sku),
                    custo_producao = VALUES(custo_producao),
                    custo_embalagem = VALUES(custo_embalagem),
                    custo_etiqueta = VALUES(custo_etiqueta),
                    custo_frete_entrada = VALUES(custo_frete_entrada),
                    taxa_comissao_ml = VALUES(taxa_comissao_ml),
                    taxa_imposto = VALUES(taxa_imposto),
                    acos_medio = VALUES(acos_medio),
                    custo_frete_gratis = VALUES(custo_frete_gratis),
                    margem_minima = VALUES(margem_minima),
                    margem_alvo = VALUES(margem_alvo)
            ");

            $stmt->execute([
                'account_id' => $this->accountId,
                'item_id' => $itemId,
                'sku' => $custos['sku'] ?? null,
                'custo_producao' => $custos['custo_producao'] ?? 0,
                'custo_embalagem' => $custos['custo_embalagem'] ?? 0,
                'custo_etiqueta' => $custos['custo_etiqueta'] ?? 0,
                'custo_frete_entrada' => $custos['custo_frete_entrada'] ?? 0,
                'taxa_comissao_ml' => $custos['taxa_comissao_ml'] ?? 16,
                'taxa_imposto' => $custos['taxa_imposto'] ?? 9,
                'acos_medio' => $custos['acos_medio'] ?? 0,
                'custo_frete_gratis' => $custos['custo_frete_gratis'] ?? 0,
                'margem_minima' => $custos['margem_minima'] ?? 10,
                'margem_alvo' => $custos['margem_alvo'] ?? 20
            ]);

            // Espelha em sku_custos para a tela Vendas / Ads usarem o mesmo CMV
            try {
                $custoProduto = (float)($custos['custo_producao'] ?? 0);
                if ($custoProduto > 0 && preg_match('/^MLB\d+$/i', $itemId)) {
                    $skuUpsert = $this->db->prepare("
                        INSERT INTO sku_custos (
                            account_id, mlb_id, custo_produto, comissao_pct, frete_medio,
                            custos_operacionais_pct, preco_minimo
                        ) VALUES (
                            :account_id, :mlb_id, :custo_produto, :comissao_pct, :frete_medio,
                            :ops_pct, :preco_minimo
                        )
                        ON DUPLICATE KEY UPDATE
                            custo_produto = VALUES(custo_produto),
                            comissao_pct = VALUES(comissao_pct),
                            frete_medio = VALUES(frete_medio),
                            custos_operacionais_pct = VALUES(custos_operacionais_pct),
                            preco_minimo = VALUES(preco_minimo)
                    ");
                    $skuUpsert->execute([
                        'account_id' => $this->accountId,
                        'mlb_id' => strtoupper($itemId),
                        'custo_produto' => $custoProduto,
                        'comissao_pct' => (float)($custos['taxa_comissao_ml'] ?? 16),
                        'frete_medio' => (float)($custos['custo_frete_gratis'] ?? 0),
                        'ops_pct' => (float)($custos['custos_operacionais_pct'] ?? 0),
                        'preco_minimo' => $custoProduto,
                    ]);
                }
            } catch (\Throwable $e) {
                // product_costs já salvo — espelho sku_custos é best-effort
            }

            return ['success' => true, 'message' => 'Custos salvos com sucesso'];
        } catch (\Throwable $e) {
            return $this->errorResponse('Erro ao salvar: ' . $e->getMessage());
        }
    }

    /**
     * Busca custos de produto do banco
     */
    public function getCustosProduto(string $itemId): ?array
    {
        if (!$this->accountId) {
            return null;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT * FROM product_costs
                WHERE account_id = :account_id AND item_id = :item_id
            ");
            $stmt->execute([
                'account_id' => $this->accountId,
                'item_id' => $itemId
            ]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }

            // Fallback: custos cadastrados via Ads/sku_custos (mesma fonte da tela Vendas)
            $skuStmt = $this->db->prepare("
                SELECT mlb_id, custo_produto, comissao_pct, frete_medio, custos_operacionais_pct
                FROM sku_custos
                WHERE account_id = :account_id AND mlb_id = :item_id
                LIMIT 1
            ");
            $skuStmt->execute([
                'account_id' => $this->accountId,
                'item_id' => $itemId,
            ]);
            $sku = $skuStmt->fetch(PDO::FETCH_ASSOC);
            if (!$sku || (float)($sku['custo_produto'] ?? 0) <= 0) {
                return null;
            }

            return [
                'account_id' => $this->accountId,
                'item_id' => $itemId,
                'sku' => null,
                'custo_producao' => (float)$sku['custo_produto'],
                'custo_embalagem' => 0.0,
                'custo_etiqueta' => 0.0,
                'custo_frete_entrada' => 0.0,
                'taxa_comissao_ml' => (float)($sku['comissao_pct'] ?? 16),
                'taxa_imposto' => 0.0,
                'acos_medio' => 0.0,
                'custo_frete_gratis' => (float)($sku['frete_medio'] ?? 0),
                'margem_minima' => 10.0,
                'margem_alvo' => 20.0,
                'custos_operacionais_pct' => (float)($sku['custos_operacionais_pct'] ?? 0),
                'source' => 'sku_custos',
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Registra alteração de preço no histórico
     */
    public function registrarAlteracaoPreco(string $itemId, float $precoAnterior, float $precoNovo, array $options = []): bool
    {
        if (!$this->accountId) {
            return false;
        }

        try {
            $variacao = $precoAnterior > 0 ? (($precoNovo - $precoAnterior) / $precoAnterior) * 100 : 0;
            $alerta = $this->analisarImpactoRanking($precoAnterior, $precoNovo);

            $stmt = $this->db->prepare("
                INSERT INTO pricing_history (
                    account_id, item_id, preco_anterior, preco_novo,
                    percentual_mudanca, origem, motivo, estrategia_usada,
                    preco_concorrente_min, preco_concorrente_medio, qtd_concorrentes,
                    margem_anterior, margem_nova, lucro_unitario_novo, alerta_ranking
                ) VALUES (
                    :account_id, :item_id, :preco_anterior, :preco_novo,
                    :percentual_mudanca, :origem, :motivo, :estrategia_usada,
                    :preco_concorrente_min, :preco_concorrente_medio, :qtd_concorrentes,
                    :margem_anterior, :margem_nova, :lucro_unitario_novo, :alerta_ranking
                )
            ");

            return $stmt->execute([
                'account_id' => $this->accountId,
                'item_id' => $itemId,
                'preco_anterior' => $precoAnterior,
                'preco_novo' => $precoNovo,
                'percentual_mudanca' => round($variacao, 2),
                'origem' => $options['origem'] ?? 'manual',
                'motivo' => $options['motivo'] ?? null,
                'estrategia_usada' => $options['estrategia'] ?? null,
                'preco_concorrente_min' => $options['preco_concorrente_min'] ?? null,
                'preco_concorrente_medio' => $options['preco_concorrente_medio'] ?? null,
                'qtd_concorrentes' => $options['qtd_concorrentes'] ?? 0,
                'margem_anterior' => $options['margem_anterior'] ?? null,
                'margem_nova' => $options['margem_nova'] ?? null,
                'lucro_unitario_novo' => $options['lucro_unitario'] ?? null,
                'alerta_ranking' => $alerta['alerta'] ?? 'verde'
            ]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Busca histórico de preços de um item
     */
    public function getHistoricoPrecos(string $itemId, int $dias = 30): array
    {
        if (!$this->accountId) {
            return [];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT
                    preco_anterior,
                    preco_novo,
                    percentual_mudanca,
                    origem,
                    motivo,
                    estrategia_usada,
                    preco_concorrente_min,
                    preco_concorrente_medio,
                    qtd_concorrentes,
                    margem_anterior,
                    margem_nova,
                    lucro_unitario_novo,
                    alerta_ranking,
                    data_mudanca
                FROM pricing_history
                WHERE account_id = :account_id
                AND item_id = :item_id
                AND data_mudanca >= DATE_SUB(NOW(), INTERVAL :dias DAY)
                ORDER BY data_mudanca DESC
            ");
            $stmt->execute([
                'account_id' => $this->accountId,
                'item_id' => $itemId,
                'dias' => $dias
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Retorna indicador visual de saúde da margem
     */
    private function getIndicadorSaude(float $margem): string
    {
        if ($margem >= 20) return 'verde';
        if ($margem >= 10) return 'amarelo';
        if ($margem >= 5) return 'laranja';
        return 'vermelho';
    }

    /**
     * Resposta de erro padronizada
     */
    private function errorResponse(string $message): array
    {
        return [
            'success' => false,
            'error' => $message
        ];
    }
}
