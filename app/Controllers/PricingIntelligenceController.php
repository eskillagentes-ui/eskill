<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\MarginCalculatorService;
use App\Services\DynamicPricingService;
use App\Services\PricingStrategyService;
use App\Services\RankingAlertService;
use App\Services\PromotionSimulatorService;
use App\Services\PricingScenarioService;
use App\Services\PricingRulesService;
use App\Services\AutoPricingOptimizerService;
use App\Services\PricingCompetitorMonitorService;
use App\Services\MercadoLivreClient;
use App\Security\AccountAccessException;
use App\Security\AccountContextResolver;
use App\Database;
use PDO;

/**
 * Pricing Intelligence Controller
 *
 * API REST para o módulo de precificação inteligente
 *
 * Endpoints:
 * - POST /api/pricing/:accountId/margin/calculate
 * - POST /api/pricing/:accountId/margin/minimum
 * - POST /api/pricing/:accountId/simulate-discount
 * - POST /api/pricing/:accountId/ranking-impact
 * - GET  /api/pricing/:accountId/costs/:itemId
 * - POST /api/pricing/:accountId/costs/:itemId
 * - GET  /api/pricing/:accountId/history/:itemId
 * - GET  /api/pricing/:accountId/competitors/:categoryId
 * - POST /api/pricing/:accountId/suggest-price
 * - GET  /api/pricing/:accountId/dashboard
 * - GET  /api/pricing/:accountId/alerts
 * - POST /api/pricing/:accountId/promotion/simulate/:itemId
 * - POST /api/pricing/:accountId/promotion/apply/:itemId
 * - GET  /api/pricing/:accountId/scenarios/:itemId
 * - POST /api/pricing/:accountId/rules
 *
 * @package App\Controllers
 */
class PricingIntelligenceController extends BaseController
{
    private int $accountId;
    private MarginCalculatorService $marginService;
    private DynamicPricingService $dynamicService;
    private PricingStrategyService $strategyService;
    private RankingAlertService $alertService;
    private PromotionSimulatorService $promotionService;
    private PricingScenarioService $scenarioService;
    private MercadoLivreClient $mlClient;
    private PDO $db;

    public function __construct(int $accountId)
    {
        parent::__construct();
        // SEC-001: accountId da rota só é aceito após AccountAccessPolicy
        try {
            $context = (new AccountContextResolver())->authorizeForCurrentActor('pricing.read', $accountId);
            $accountId = $context->accountId();
            $this->mlClient = MercadoLivreClient::fromAuthorizedContext($context);
        } catch (AccountAccessException $e) {
            throw new \RuntimeException($e->getMessage(), $e->httpStatus(), $e);
        }
        $this->accountId = $accountId;
        $this->marginService = new MarginCalculatorService($accountId);
        $this->dynamicService = new DynamicPricingService($accountId);
        $this->strategyService = new PricingStrategyService($accountId);
        $this->alertService = new RankingAlertService($accountId);
        $this->promotionService = new PromotionSimulatorService($accountId);
        $this->scenarioService = new PricingScenarioService($accountId);
        $this->db = Database::getInstance();
    }

    /**
     * POST /api/pricing/:accountId/margin/calculate
     * Calcula margem real de um produto
     *
     * Body: {
     *   "preco_venda": 199.90,
     *   "custo_producao": 80.00,
     *   "custo_embalagem": 5.00,
     *   "taxa_comissao_ml": 16,
     *   "taxa_imposto": 9,
     *   "acos_medio": 5,
     *   "custo_frete_gratis": 15.00
     * }
     */
    public function calculateMargin(): void
    {
        $this->jsonResponse();
        $data = $this->getJsonInput();

        if (!isset($data['preco_venda'])) {
            $this->error('preco_venda é obrigatório', 400);
            return;
        }

        if (filter_var($data['preco_venda'], FILTER_VALIDATE_FLOAT) === false || (float)$data['preco_venda'] <= 0) {
            $this->error('preco_venda deve ser um número positivo', 400);
            return;
        }

        $result = $this->marginService->calcularMargem(
            (float)$data['preco_venda'],
            $data
        );

        echo json_encode($result);
    }

    /**
     * POST /api/pricing/:accountId/margin/minimum
     * Calcula preço mínimo para atingir margem alvo
     *
     * Body: {
     *   "custo_producao": 80.00,
     *   "margem_alvo": 15,
     *   ...outros custos
     * }
     */
    public function calculateMinimumPrice(): void
    {
        $this->jsonResponse();
        $data = $this->getJsonInput();

        $rawMargem = $data['margem_alvo'] ?? 10;
        if (filter_var($rawMargem, FILTER_VALIDATE_FLOAT) === false
            || (float)$rawMargem < 0
            || (float)$rawMargem > 100
        ) {
            $this->error('margem_alvo deve ser um número entre 0 e 100', 400);
            return;
        }

        $margemAlvo = (float)$rawMargem;
        $result = $this->marginService->calcularPrecoMinimo($data, $margemAlvo);

        echo json_encode($result);
    }

    /**
     * POST /api/pricing/:accountId/simulate-discount
     * Simula impacto de desconto na margem
     *
     * Body: {
     *   "preco_original": 199.90,
     *   "desconto_percent": 15,
     *   "custo_producao": 80.00,
     *   ...outros custos
     * }
     */
    public function simulateDiscount(): void
    {
        $this->jsonResponse();
        $data = $this->getJsonInput();

        if (!isset($data['preco_original']) || !isset($data['desconto_percent'])) {
            $this->error('preco_original e desconto_percent são obrigatórios', 400);
            return;
        }

        if (filter_var($data['preco_original'], FILTER_VALIDATE_FLOAT) === false || (float)$data['preco_original'] <= 0) {
            $this->error('preco_original deve ser um número positivo', 400);
            return;
        }

        if (filter_var($data['desconto_percent'], FILTER_VALIDATE_FLOAT) === false
            || (float)$data['desconto_percent'] < 0
            || (float)$data['desconto_percent'] > 100
        ) {
            $this->error('desconto_percent deve ser um número entre 0 e 100', 400);
            return;
        }

        $result = $this->marginService->simularDesconto(
            (float)$data['preco_original'],
            (float)$data['desconto_percent'],
            $data
        );

        echo json_encode($result);
    }

    /**
     * POST /api/pricing/:accountId/ranking-impact
     * Analisa impacto de alteração de preço no ranking
     *
     * Body: {
     *   "preco_atual": 199.90,
     *   "preco_novo": 229.90
     * }
     */
    public function analyzeRankingImpact(): void
    {
        $this->jsonResponse();
        $data = $this->getJsonInput();

        if (!isset($data['preco_atual']) || !isset($data['preco_novo'])) {
            $this->error('preco_atual e preco_novo são obrigatórios', 400);
            return;
        }

        $result = $this->marginService->analisarImpactoRanking(
            (float)$data['preco_atual'],
            (float)$data['preco_novo']
        );

        echo json_encode($result);
    }

    /**
     * GET /api/pricing/:accountId/costs/:itemId
     * Busca custos cadastrados de um produto
     */
    public function getCosts(string $itemId): void
    {
        $this->jsonResponse();

        $custos = $this->marginService->getCustosProduto($itemId);

        if (!$custos) {
            // Tenta buscar dados básicos do ML
            $item = $this->mlClient->get("/items/{$itemId}");

            echo json_encode([
                'success' => true,
                'item_id' => $itemId,
                'custos' => null,
                'item_info' => $item ? [
                    'titulo' => $item['title'] ?? null,
                    'preco' => $item['price'] ?? null,
                    'categoria' => $item['category_id'] ?? null,
                    'tipo_anuncio' => $item['listing_type_id'] ?? null
                ] : null,
                'message' => 'Custos não cadastrados. Configure os custos para cálculo preciso.'
            ]);
            return;
        }

        echo json_encode([
            'success' => true,
            'item_id' => $itemId,
            'custos' => $custos
        ]);
    }

    /**
     * POST /api/pricing/:accountId/costs/:itemId
     * Salva custos de um produto
     *
     * Body: {
     *   "sku": "ABC123",
     *   "custo_producao": 80.00,
     *   "custo_embalagem": 5.00,
     *   "taxa_comissao_ml": 16,
     *   "taxa_imposto": 9,
     *   "acos_medio": 5,
     *   "custo_frete_gratis": 15.00,
     *   "margem_minima": 10,
     *   "margem_alvo": 20
     * }
     */
    public function saveCosts(string $itemId): void
    {
        $this->jsonResponse();
        $data = $this->getJsonInput();

        $result = $this->marginService->salvarCustosProduto($itemId, $data);

        http_response_code($result['success'] ? 200 : 500);
        echo json_encode($result);
    }

    /**
     * GET /api/pricing/:accountId/history/:itemId
     * Histórico de alterações de preço
     */
    public function getHistory(string $itemId): void
    {
        $this->jsonResponse();

        $dias = $this->request->getInt('dias', 0);
        if ($dias <= 0) {
            // Compat: alguns frontends usam days
            $dias = $this->request->getInt('days', 30);
        }
        $dias = max(1, min(3650, $dias));

        $historico = $this->marginService->getHistoricoPrecos($itemId, $dias);

        // Compat/UI: retornar também no formato "history" (para gráficos)
        // Mantém "historico" como retorno bruto (ordenado DESC pelo DB)
        $historySource = array_reverse($historico);
        $history = array_map(static function (array $row): array {
            $dataMudanca = $row['data_mudanca'] ?? null;
            $dateLabel = null;
            if (!empty($dataMudanca)) {
                $ts = strtotime((string)$dataMudanca);
                $dateLabel = $ts ? date('d/m', $ts) : null;
            }

            $alerta = isset($row['alerta_ranking']) && is_string($row['alerta_ranking']) ? $row['alerta_ranking'] : null;
            $positionPercentage = match ($alerta) {
                'verde' => 6.0,
                'amarelo' => 12.5,
                'vermelho' => 18.0,
                default => null,
            };

            return [
                'date' => $dateLabel,
                'price' => isset($row['preco_novo']) ? (float)$row['preco_novo'] : null,
                'margin' => isset($row['margem_nova']) ? (float)$row['margem_nova'] : null,
                'min_price' => isset($row['preco_concorrente_min']) ? (float)$row['preco_concorrente_min'] : null,
                'avg_price' => isset($row['preco_concorrente_medio']) ? (float)$row['preco_concorrente_medio'] : null,
                'position_percentage' => $positionPercentage,
            ];
        }, $historySource);

        echo json_encode([
            'success' => true,
            'item_id' => $itemId,
            'periodo_dias' => $dias,
            'total' => count($historico),
            'historico' => $historico,
            'history' => $history,
        ]);
    }

    /**
     * GET /api/pricing/:accountId/competitors/:categoryId
     * Análise de preços de concorrentes
     */
    public function analyzeCompetitors(string $categoryId): void
    {
        $this->jsonResponse();

        $brand = $this->request->get('brand');
        $keyword = $this->request->get('q');

        $result = $this->strategyService->analyzeCompetitorPrices($categoryId, $brand, $keyword);

        echo json_encode($result);
    }

    /**
     * POST /api/pricing/:accountId/suggest-price
     * Sugere preço baseado em estratégia
     *
     * Body: {
     *   "category_id": "MLB1234",
     *   "strategy": "competitive",
     *   "cost": 80.00,
     *   "target_margin": 0.15,
     *   "keyword": "produto exemplo"
     * }
     */
    public function suggestPrice(): void
    {
        $this->jsonResponse();
        $data = $this->getJsonInput();

        if (!isset($data['category_id'])) {
            $this->error('category_id é obrigatório', 400);
            return;
        }

        // Análise de concorrência
        $analysis = $this->strategyService->analyzeCompetitorPrices(
            $data['category_id'],
            $data['brand'] ?? null,
            $data['keyword'] ?? null
        );

        if (isset($analysis['error'])) {
            echo json_encode($analysis);
            return;
        }

        // Sugestão de preço
        $suggestion = $this->strategyService->suggestPrice(
            $analysis,
            $data['strategy'] ?? 'competitive',
            [
                'cost' => $data['cost'] ?? null,
                'target_margin' => $data['target_margin'] ?? null
            ]
        );

        // Se temos custo, calcular margem do preço sugerido
        if (isset($data['cost']) && $suggestion['success'] && $suggestion['suggested_price']) {
            $custos = $data;
            $custos['custo_producao'] = $data['cost'];

            $margem = $this->marginService->calcularMargem(
                $suggestion['suggested_price'],
                $custos
            );

            $suggestion['margem_calculada'] = $margem;
        }

        echo json_encode(array_merge($analysis, ['suggestion' => $suggestion]));
    }

    /**
     * GET /api/pricing/:accountId/dashboard
     * Dados do dashboard de precificação
     */
    public function getDashboard(): void
    {
        $this->jsonResponse();

        try {
            // Resumo de alertas
            $alertas = $this->getAlertasSummary();

            // Itens com margem crítica
            $itensCriticos = $this->getItensMargem('critica', 10);

            // Últimas alterações de preço
            $ultimasAlteracoes = $this->getUltimasAlteracoes(10);

            // Estatísticas gerais
            $stats = $this->getEstatisticasGerais();

            echo json_encode([
                'success' => true,
                'alertas' => $alertas,
                'itens_criticos' => $itensCriticos,
                'ultimas_alteracoes' => $ultimasAlteracoes,
                'estatisticas' => $stats
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao carregar dashboard: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/alerts
     * Lista alertas de pricing
     */
    public function getAlerts(): void
    {
        $this->jsonResponse();

        try {
            $nivel = $this->request->get('nivel');
            $naoLidos = $this->request->get('nao_lidos') !== null;
            $limit = $this->request->getInt('limit', 50);

            $days = $this->request->getInt('days', 0);
            if ($days <= 0) {
                $days = $this->request->getInt('dias', 0);
            }
            $cutoff = null;
            if ($days > 0) {
                $days = max(1, min(365, $days));
                $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
            }

            $sql = "SELECT * FROM pricing_ranking_alerts WHERE account_id = :account_id";
            $params = ['account_id' => $this->accountId];

            if ($nivel) {
                $sql .= " AND nivel = :nivel";
                $params['nivel'] = $nivel;
            }

            if ($naoLidos) {
                $sql .= " AND lido = 0";
            }

            if ($cutoff !== null) {
                $sql .= " AND criado_em >= :cutoff";
                $params['cutoff'] = $cutoff;
            }

            $sql .= " ORDER BY criado_em DESC LIMIT {$limit}";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $alertas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Compat/UI: normalizar keys usadas em views antigas
            $alerts = array_map(static function (array $a): array {
                $alertType = match ($a['nivel'] ?? null) {
                    'vermelho' => 'danger',
                    'amarelo' => 'warning',
                    'verde' => 'excellent',
                    default => 'warning',
                };

                $title = match ($a['tipo_alerta'] ?? null) {
                    'aumento_preco' => 'Aumento de preço',
                    'queda_vendas' => 'Queda de vendas',
                    'perda_posicao' => 'Perda de posição',
                    'concorrente_agressivo' => 'Concorrente agressivo',
                    default => 'Alerta',
                };

                return [
                    'id' => isset($a['id']) ? (int)$a['id'] : 0,
                    'item_id' => $a['item_id'] ?? null,
                    'alert_type' => $alertType,
                    'title' => $title,
                    'alert_message' => $a['mensagem'] ?? null,
                    'current_price' => isset($a['preco_atual']) ? (float)$a['preco_atual'] : null,
                    'suggested_price' => isset($a['preco_recomendado']) ? (float)$a['preco_recomendado'] : null,
                    'is_read' => (bool)($a['lido'] ?? 0),
                    'is_resolved' => (bool)($a['resolvido'] ?? 0),
                    'created_at' => $a['criado_em'] ?? null,
                ];
            }, $alertas);

            echo json_encode([
                'success' => true,
                'total' => count($alertas),
                'alertas' => $alertas,
                'alerts' => $alerts
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao buscar alertas: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/alerts/:alertId/read
     * Marca alerta como lido
     */
    public function markAlertRead(int $alertId): void
    {
        $this->jsonResponse();

        try {
            $stmt = $this->db->prepare("
                UPDATE pricing_ranking_alerts
                SET lido = 1
                WHERE id = :id AND account_id = :account_id
            ");
            $stmt->execute(['id' => $alertId, 'account_id' => $this->accountId]);

            echo json_encode(['success' => true]);
        } catch (\Throwable $e) {
            $this->error('Erro ao atualizar alerta');
        }
    }

    /**
     * POST /api/pricing/:accountId/apply/:itemId
     * Aplica novo preço no Mercado Livre
     *
     * Body: {
     *   "novo_preco": 199.90,
     *   "motivo": "Ajuste competitivo",
     *   "estrategia": "competitive"
     * }
     */
    public function applyPrice(string $itemId): void
    {
        $this->jsonResponse();
        $guard = \App\Helpers\MlWriteAutomation::guard('PricingIntelligenceController::applyPrice');
        if (!$guard['allowed']) {
            $this->error($guard['reason'], 403);
            return;
        }

        $data = $this->getJsonInput();

        if (!isset($data['novo_preco'])) {
            $this->error('novo_preco é obrigatório', 400);
            return;
        }

        $novoPreco = (float)$data['novo_preco'];

        try {
            // Buscar preço atual
            $item = $this->mlClient->get("/items/{$itemId}");

            if (!$item || isset($item['error'])) {
                $this->error('Item não encontrado', 404);
                return;
            }

            $precoAtual = (float)$item['price'];

            // Verificar impacto no ranking
            $impacto = $this->marginService->analisarImpactoRanking($precoAtual, $novoPreco);

            if ($impacto['alerta'] === 'vermelho' && empty($data['force'])) {
                echo json_encode([
                    'success' => false,
                    'warning' => true,
                    'message' => $impacto['mensagem'],
                    'recomendacao' => $impacto['recomendacao'],
                    'preco_maximo_seguro' => $impacto['preco_maximo_seguro'],
                    'action_required' => 'Envie force=true para aplicar mesmo assim'
                ]);
                return;
            }

            // Aplicar preço no ML
            $response = $this->mlClient->put("/items/{$itemId}", [
                'price' => $novoPreco
            ]);

            if (isset($response['error'])) {
                $this->error('Erro ao atualizar preço no ML: ' . ($response['message'] ?? 'Erro desconhecido'));
                return;
            }

            // Registrar no histórico
            $custos = $this->marginService->getCustosProduto($itemId);
            $margemNova = null;
            $lucroNovo = null;

            if ($custos) {
                $calc = $this->marginService->calcularMargem($novoPreco, $custos);
                $margemNova = $calc['margem_real'] ?? null;
                $lucroNovo = $calc['lucro_unitario'] ?? null;
            }

            $this->marginService->registrarAlteracaoPreco($itemId, $precoAtual, $novoPreco, [
                'origem' => 'manual',
                'motivo' => $data['motivo'] ?? null,
                'estrategia' => $data['estrategia'] ?? null,
                'margem_nova' => $margemNova,
                'lucro_unitario' => $lucroNovo
            ]);

            echo json_encode([
                'success' => true,
                'item_id' => $itemId,
                'preco_anterior' => $precoAtual,
                'preco_novo' => $novoPreco,
                'variacao_percent' => round((($novoPreco - $precoAtual) / $precoAtual) * 100, 2),
                'impacto_ranking' => $impacto['alerta'],
                'margem_nova' => $margemNova,
                'lucro_unitario' => $lucroNovo
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao aplicar preço: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/items
     * Lista itens com dados de pricing
     */
    /**
     * GET /api/pricing-intelligence/:accountId/status
     * Verifica status da conexão ML e informações da conta
     */
    public function getStatus(): void
    {
        $this->jsonResponse();

        try {
            // Verificar conta no banco
            $stmt = $this->db->prepare("
                SELECT id, nickname, email, ml_user_id, status,
                       CASE WHEN token_expires_at > NOW() THEN 'válido' ELSE 'expirado' END as token_status,
                       token_expires_at,
                       created_at
                FROM ml_accounts
                WHERE id = :id
            ");
            $stmt->execute(['id' => $this->accountId]);
            $account = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$account) {
                echo json_encode([
                    'success' => false,
                    'status' => 'not_found',
                    'message' => "Conta {$this->accountId} não encontrada no sistema",
                    'dica' => 'Faça login com sua conta Mercado Livre em /auth/authorize'
                ]);
                return;
            }

            // Se token expirado, tentar renovar automaticamente
            $tokenRenovado = false;
            if ($account['token_status'] === 'expirado' || $account['status'] !== 'active') {
                $authService = new \App\Services\MercadoLivreAuthService();
                $tokenRenovado = $authService->refreshToken($this->accountId);

                if ($tokenRenovado) {
                    // Recarregar dados da conta após renovação
                    $stmt->execute(['id' => $this->accountId]);
                    $account = $stmt->fetch(\PDO::FETCH_ASSOC);

                    // Recriar o cliente ML com o novo token
                    $this->mlClient = new \App\Services\MercadoLivreClient($this->accountId);
                }
            }

            // Tentar conexão ML
            $mlStatus = 'desconectado';
            $mlInfo = null;
            $mlError = null;

            try {
                $meData = $this->mlClient->get('/users/me');
                if ($meData && !isset($meData['error'])) {
                    $mlStatus = 'conectado';
                    $mlInfo = [
                        'user_id' => $meData['id'] ?? null,
                        'nickname' => $meData['nickname'] ?? null,
                        'seller_reputation' => $meData['seller_reputation']['level_id'] ?? null,
                        'points' => $meData['points'] ?? null,
                        'site_id' => $meData['site_id'] ?? null
                    ];
                } else {
                    $mlError = $meData['error'] ?? $meData['message'] ?? 'Erro desconhecido';
                }
            } catch (\Throwable $e) {
                $mlError = $e->getMessage();
            }

            // Estatísticas do módulo de pricing
            $statsStmt = $this->db->prepare("SELECT COUNT(*) as total FROM product_costs WHERE account_id = :id");
            $statsStmt->execute(['id' => $this->accountId]);
            $stats = $statsStmt->fetch(\PDO::FETCH_ASSOC);

            $localItemsCount = 0;
            try {
                $localItemsStmt = $this->db->prepare("SELECT COUNT(*) FROM ml_items WHERE account_id = :id");
                $localItemsStmt->execute(['id' => $this->accountId]);
                $localItemsCount = (int)$localItemsStmt->fetchColumn();
            } catch (\Throwable $e) {
                $localItemsCount = 0;
            }

            echo json_encode([
                'success' => true,
                'account' => [
                    'id' => $account['id'],
                    'nickname' => $account['nickname'],
                    'email' => $account['email'],
                    'ml_user_id' => $account['ml_user_id'],
                    'token_status' => $account['token_status'],
                    'token_expires_at' => $account['token_expires_at'],
                    'account_status' => $account['status']
                ],
                'ml_connection' => $mlStatus,
                'ml_info' => $mlInfo,
                'ml_error' => $mlError,
                'token_renewed' => $tokenRenovado,
                'pricing_stats' => [
                    'products_with_costs' => (int)($stats['total'] ?? 0)
                ],
                'preview_mode_available' => $localItemsCount > 0,
                'local_items_count' => $localItemsCount,
                'auth_url' => $mlStatus !== 'conectado' ? '/auth/authorize' : null
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao verificar status: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing-intelligence/:accountId/refresh-token
     * Força renovação do token OAuth do Mercado Livre
     */
    public function refreshToken(): void
    {
        $this->jsonResponse();

        try {
            $authService = new \App\Services\MercadoLivreAuthService();
            $success = $authService->refreshToken($this->accountId);

            if ($success) {
                // Recarregar cliente ML
                $this->mlClient = new \App\Services\MercadoLivreClient($this->accountId);

                // Verificar conexão
                $meData = $this->mlClient->get('/users/me');
                $connected = $meData && !isset($meData['error']);

                // Buscar nova data de expiração
                $stmt = $this->db->prepare("SELECT token_expires_at FROM ml_accounts WHERE id = :id");
                $stmt->execute(['id' => $this->accountId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'message' => 'Token renovado com sucesso',
                    'token_expires_at' => $row['token_expires_at'] ?? null,
                    'ml_connected' => $connected,
                    'ml_user' => $connected ? ($meData['nickname'] ?? null) : null
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Falha ao renovar token. O refresh_token expirou ou é inválido.',
                    'error_type' => 'invalid_grant',
                    'action_required' => 'Reconecte sua conta clicando no botão abaixo',
                    'reconnect_url' => '/auth/authorize',
                    'help' => 'Tokens do Mercado Livre expiram se não forem usados por muito tempo. ' .
                        'Você precisará autorizar novamente o acesso à sua conta.'
                ]);
            }
        } catch (\Throwable $e) {
            $this->error('Erro ao renovar token: ' . $e->getMessage());
        }
    }

    public function listItems(): void
    {
        $this->jsonResponse();

        try {
            $page = max(1, (int)$this->request->getInt('page', 1));
            $limit = $this->request->getIntClamped('limit', 1, 100, 20);
            $offset = max(0, min(1000000, ($page - 1) * $limit));

            $status = $this->request->get('status');
            $margemMin = $this->request->get('margem_min');
            $margemMax = $this->request->get('margem_max');
            $categoria = $this->request->get('categoria');
            $search = $this->request->get('q');
            $previewMode = $this->request->getBool('preview') || $this->request->getBool('demo');

            // Modo preview local - retorna dados reais do cache local
            if ($previewMode) {
                echo json_encode($this->getPreviewItems($page, $limit));
                return;
            }

            // Verificar e renovar token automaticamente se necessário
            $tokenRefreshed = false;
            $authService = new \App\Services\MercadoLivreAuthService();
            if ($authService->ensureValidToken($this->accountId)) {
                // Token foi renovado, recriar cliente
                $this->mlClient = new \App\Services\MercadoLivreClient($this->accountId);
                $tokenRefreshed = true;
            }

            // Buscar itens do ML usando endpoint correto
            $params = ['status' => $status ?? 'active', 'limit' => $limit, 'offset' => $offset];

            try {
                $mlItems = $this->mlClient->getMyItems($params);
            } catch (\Throwable $mlError) {
                // Se falhar e token não foi renovado ainda, tentar renovar e tentar novamente
                if (!$tokenRefreshed) {
                    $refreshed = $authService->refreshToken($this->accountId);
                    if ($refreshed) {
                        $this->mlClient = new \App\Services\MercadoLivreClient($this->accountId);
                        try {
                            $mlItems = $this->mlClient->getMyItems($params);
                        } catch (\Throwable $retryError) {
                            $mlItems = ['error' => $retryError->getMessage()];
                        }
                    } else {
                        $mlItems = ['error' => $mlError->getMessage()];
                    }
                } else {
                    $mlItems = ['error' => $mlError->getMessage()];
                }
            }

            // Se falhar ao buscar do ML, tentar retornar itens com custos cadastrados no banco
            if (!$mlItems || isset($mlItems['error']) || empty($mlItems['results'])) {
                // Fallback real: combinar custos cadastrados com dados locais sincronizados
                $where = ["pc.account_id = :account_id"];
                $params = ['account_id' => $this->accountId];

                if (!empty($status)) {
                    $where[] = 'm.status = :status';
                    $params['status'] = $status;
                }

                if (!empty($categoria)) {
                    $where[] = 'm.category_id = :categoria';
                    $params['categoria'] = $categoria;
                }

                if (!empty($search)) {
                    $where[] = '(pc.item_id LIKE :search OR pc.sku LIKE :search OR m.title LIKE :search)';
                    $params['search'] = '%' . $search . '%';
                }

                $whereSql = implode(' AND ', $where);

                $stmt = $this->db->prepare("
                    SELECT
                        pc.item_id,
                        pc.sku,
                        pc.custo_producao,
                        pc.custo_embalagem,
                        pc.custo_etiqueta,
                        pc.custo_frete_gratis,
                        pc.taxa_comissao_ml,
                        pc.taxa_imposto,
                        pc.acos_medio,
                        pc.margem_minima,
                        pc.margem_alvo,
                        m.title AS local_title,
                        m.price AS local_price,
                        m.status AS local_status,
                        m.category_id AS local_category,
                        m.available_quantity AS local_stock,
                        m.sold_quantity AS local_sold,
                        m.thumbnail AS local_thumbnail
                    FROM product_costs pc
                    LEFT JOIN ml_items m
                        ON m.account_id = pc.account_id
                       AND m.id = pc.item_id
                    WHERE {$whereSql}
                    ORDER BY pc.atualizado_em DESC
                    LIMIT {$limit} OFFSET {$offset}
                ");

                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value);
                }
                $stmt->execute();
                $itemsFromDb = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                // Contar total
                $countStmt = $this->db->prepare("
                    SELECT COUNT(*)
                    FROM product_costs pc
                    LEFT JOIN ml_items m
                        ON m.account_id = pc.account_id
                       AND m.id = pc.item_id
                    WHERE {$whereSql}
                ");
                foreach ($params as $key => $value) {
                    $countStmt->bindValue($key, $value);
                }
                $countStmt->execute();
                $total = (int)$countStmt->fetchColumn();

                // Se não há itens cadastrados localmente
                if (empty($itemsFromDb)) {
                    echo json_encode([
                        'success' => true,
                        'page' => $page,
                        'limit' => $limit,
                        'total' => 0,
                        'items' => [],
                        'ml_status' => 'desconectado',
                        'aviso' => 'Nenhum item local encontrado. Sincronize itens do Mercado Livre para análise completa.'
                    ]);
                    return;
                }

                $items = [];
                foreach ($itemsFromDb as $custos) {
                    $price = isset($custos['local_price']) ? (float)$custos['local_price'] : null;
                    $margem = null;
                    $lucro = null;
                    $indicador = 'cinza';

                    if ($price !== null && $price > 0) {
                        $calc = $this->marginService->calcularMargem($price, [
                            'sku' => $custos['sku'] ?? null,
                            'custo_producao' => (float)($custos['custo_producao'] ?? 0),
                            'custo_embalagem' => (float)($custos['custo_embalagem'] ?? 0),
                            'custo_etiqueta' => (float)($custos['custo_etiqueta'] ?? 0),
                            'custo_frete_gratis' => (float)($custos['custo_frete_gratis'] ?? 0),
                            'taxa_comissao_ml' => (float)($custos['taxa_comissao_ml'] ?? 0),
                            'taxa_imposto' => (float)($custos['taxa_imposto'] ?? 0),
                            'acos_medio' => (float)($custos['acos_medio'] ?? 0),
                            'margem_minima' => (float)($custos['margem_minima'] ?? 0),
                            'margem_alvo' => (float)($custos['margem_alvo'] ?? 0),
                        ]);

                        $margem = isset($calc['margem_real']) ? (float)$calc['margem_real'] : null;
                        $lucro = isset($calc['lucro_unitario']) ? (float)$calc['lucro_unitario'] : null;
                        $indicador = $calc['indicador'] ?? 'cinza';
                    }

                    if ($margemMin !== null && ($margem === null || $margem < (float)$margemMin)) {
                        continue;
                    }
                    if ($margemMax !== null && ($margem === null || $margem > (float)$margemMax)) {
                        continue;
                    }

                    $items[] = [
                        'id' => $custos['item_id'],
                        'titulo' => $custos['local_title'] ?? ('Item ' . $custos['item_id']),
                        'sku' => $custos['sku'],
                        'preco' => $price,
                        'status' => $custos['local_status'] ?? 'unknown',
                        'categoria' => $custos['local_category'] ?? null,
                        'tipo_anuncio' => null,
                        'estoque' => isset($custos['local_stock']) ? (int)$custos['local_stock'] : null,
                        'vendidos' => isset($custos['local_sold']) ? (int)$custos['local_sold'] : null,
                        'thumbnail' => $custos['local_thumbnail'] ?? null,
                        'margem' => $margem,
                        'lucro_unitario' => $lucro,
                        'indicador' => $indicador,
                        'custos_cadastrados' => true,
                        'aviso' => 'Dados obtidos do cache local. Conecte ao Mercado Livre para sincronização em tempo real.'
                    ];
                }

                echo json_encode([
                    'success' => true,
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'items' => $items,
                    'ml_status' => 'desconectado',
                    'aviso' => 'Conexão com Mercado Livre indisponível. Mostrando dados locais sincronizados.'
                ]);
                return;
            }

            $itemIds = $mlItems['results'] ?? [];
            $items = [];

            if (!empty($itemIds)) {
                // Buscar detalhes dos itens
                $itemsData = $this->mlClient->get("/items", ['ids' => implode(',', array_slice($itemIds, 0, 20))]);

                foreach ($itemsData as $itemData) {
                    $item = $itemData['body'] ?? $itemData;
                    $itemId = $item['id'];

                    // Buscar custos do banco
                    $custos = $this->marginService->getCustosProduto($itemId);

                    $margem = null;
                    $lucro = null;
                    $indicador = 'cinza';

                    if ($custos) {
                        $calc = $this->marginService->calcularMargem((float)$item['price'], $custos);
                        $margem = $calc['margem_real'] ?? null;
                        $lucro = $calc['lucro_unitario'] ?? null;
                        $indicador = $calc['indicador'] ?? 'cinza';
                    }

                    // Filtrar por margem se solicitado
                    if ($margemMin !== null && ($margem === null || $margem < (float)$margemMin)) continue;
                    if ($margemMax !== null && ($margem === null || $margem > (float)$margemMax)) continue;

                    $items[] = [
                        'id' => $itemId,
                        'titulo' => $item['title'],
                        'sku' => $custos['sku'] ?? null,
                        'preco' => (float)$item['price'],
                        'status' => $item['status'],
                        'categoria' => $item['category_id'],
                        'tipo_anuncio' => $item['listing_type_id'],
                        'estoque' => $item['available_quantity'] ?? 0,
                        'vendidos' => $item['sold_quantity'] ?? 0,
                        'thumbnail' => $item['thumbnail'] ?? null,
                        'margem' => $margem,
                        'lucro_unitario' => $lucro,
                        'indicador' => $indicador,
                        'custos_cadastrados' => $custos !== null
                    ];
                }
            }

            echo json_encode([
                'success' => true,
                'page' => $page,
                'limit' => $limit,
                'total' => $mlItems['paging']['total'] ?? 0,
                'items' => $items,
                'ml_status' => 'conectado',
                'token_refreshed' => $tokenRefreshed
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao listar itens: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/bulk-costs
     * Importação em lote de custos
     *
     * Body: {
     *   "items": [
     *     {"item_id": "MLB123", "sku": "ABC", "custo_producao": 80},
     *     ...
     *   ]
     * }
     */
    public function bulkSaveCosts(): void
    {
        $this->jsonResponse();
        $data = $this->getJsonInput();

        if (!isset($data['items']) || !is_array($data['items'])) {
            $this->error('items deve ser um array', 400);
            return;
        }

        $sucesso = 0;
        $falhas = [];

        foreach ($data['items'] as $item) {
            if (!isset($item['item_id'])) {
                $falhas[] = ['item' => $item, 'erro' => 'item_id ausente'];
                continue;
            }

            $result = $this->marginService->salvarCustosProduto($item['item_id'], $item);

            if ($result['success']) {
                $sucesso++;
            } else {
                $falhas[] = ['item_id' => $item['item_id'], 'erro' => $result['error'] ?? 'Erro desconhecido'];
            }
        }

        echo json_encode([
            'success' => true,
            'processados' => count($data['items']),
            'sucesso' => $sucesso,
            'falhas' => count($falhas),
            'detalhes_falhas' => $falhas
        ]);
    }

    // ==================== MÉTODOS AUXILIARES ====================

    private function getAlertasSummary(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    nivel,
                    COUNT(*) as total,
                    SUM(CASE WHEN lido = 0 THEN 1 ELSE 0 END) as nao_lidos
                FROM pricing_ranking_alerts
                WHERE account_id = :account_id
                AND criado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY nivel
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getItensMargem(string $tipo, int $limit): array
    {
        try {
            $limitSql = max(1, min(200, (int)$limit));
            // Buscar itens com custos cadastrados
            $stmt = $this->db->prepare("
                SELECT
                    pc.item_id,
                    pc.sku,
                    pc.custo_producao,
                    pc.custo_embalagem,
                    pc.custo_etiqueta,
                    pc.custo_frete_entrada,
                    pc.custo_frete_gratis,
                    pc.taxa_comissao_ml,
                    pc.taxa_imposto,
                    pc.acos_medio,
                    pc.margem_minima,
                    pc.margem_alvo
                FROM product_costs pc
                WHERE pc.account_id = :account_id
                LIMIT {$limitSql}
            ");
            $stmt->bindValue('account_id', $this->accountId, PDO::PARAM_INT);
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calcular margem para cada item (precisa buscar preço do ML)
            $result = [];
            foreach ($items as $item) {
                // Por enquanto retorna dados básicos sem calcular margem real
                // (precisaria buscar preço atual do ML para calcular)
                $result[] = [
                    'item_id' => $item['item_id'],
                    'sku' => $item['sku'],
                    'custo_total' => (float)$item['custo_producao'] + (float)$item['custo_embalagem'] + (float)$item['custo_etiqueta'],
                    'margem_alvo' => (float)$item['margem_alvo']
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getUltimasAlteracoes(int $limit): array
    {
        try {
            $limitSql = max(1, min(200, (int)$limit));
            $stmt = $this->db->prepare("
                SELECT
                    item_id, preco_anterior, preco_novo,
                    percentual_mudanca, origem, alerta_ranking,
                    data_mudanca
                FROM pricing_history
                WHERE account_id = :account_id
                ORDER BY data_mudanca DESC
                LIMIT {$limitSql}
            ");
            $stmt->bindValue('account_id', $this->accountId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getEstatisticasGerais(): array
    {
        try {
            // Conta total de produtos com custos cadastrados
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total_produtos
                FROM product_costs
                WHERE account_id = :account_id
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            // Conta alterações de preço por período
            $stmt2 = $this->db->prepare("
                SELECT
                    COUNT(*) as total_alteracoes,
                    AVG(margem_nova) as margem_media,
                    MIN(margem_nova) as margem_minima,
                    MAX(margem_nova) as margem_maxima
                FROM pricing_history
                WHERE account_id = :account_id
                AND margem_nova IS NOT NULL
                AND data_mudanca >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt2->execute(['account_id' => $this->accountId]);
            $histStats = $stmt2->fetch(PDO::FETCH_ASSOC);

            return [
                'total_produtos' => (int)($stats['total_produtos'] ?? 0),
                'total_alteracoes_30d' => (int)($histStats['total_alteracoes'] ?? 0),
                'margem_media' => round((float)($histStats['margem_media'] ?? 0), 2),
                'margem_minima' => round((float)($histStats['margem_minima'] ?? 0), 2),
                'margem_maxima' => round((float)($histStats['margem_maxima'] ?? 0), 2),
                'distribuicao' => [
                    'critica' => 0,
                    'baixa' => 0,
                    'media' => 0,
                    'boa' => 0
                ]
            ];
        } catch (\Throwable $e) {
            return [
                'total_produtos' => 0,
                'total_alteracoes_30d' => 0,
                'margem_media' => 0,
                'margem_minima' => 0,
                'margem_maxima' => 0,
                'distribuicao' => ['critica' => 0, 'baixa' => 0, 'media' => 0, 'boa' => 0]
            ];
        }
    }

    private function jsonResponse(): void
    {
        header('Content-Type: application/json');
    }

    private function getJsonInput(): array
    {
        return $this->request->json() ?? [];
    }

    private function error(string $message, int $code = 500): void
    {
        http_response_code($code);
        echo json_encode(['success' => false, 'error' => $message]);
    }

    /**
     * GET /api/pricing/:accountId/alerts/analyze/:itemId
     * Analisa posição no ranking de um item específico
     */
    public function analyzeItemRanking(string $itemId): void
    {
        $this->jsonResponse();

        try {
            $result = $this->alertService->analyzeItem($itemId);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao analisar ranking: ' . $e->getMessage());
        }
    }

    // ==================== SIMULADOR DE PROMOÇÕES ====================

    /**
     * POST /api/pricing/:accountId/promotion/simulate/:itemId
     * Simula uma promoção para um item
     *
     * Body: {
     *   "desconto": 15,
     *   "custos": { "custo_producao": 80, ... },  // opcional
     *   "salvar": false  // opcional
     * }
     */
    public function simulatePromotion(string $itemId): void
    {
        $this->jsonResponse();
        $data = $this->getJsonInput();

        if (!isset($data['desconto'])) {
            $this->error('desconto é obrigatório (0-50%)', 400);
            return;
        }

        try {
            $result = $this->promotionService->simularPromocao(
                $itemId,
                (float)$data['desconto'],
                [
                    'custos' => $data['custos'] ?? null,
                    'salvar' => $data['salvar'] ?? false
                ]
            );
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao simular promoção: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/promotion/scenarios/:itemId
     * Gera cenários de desconto para um item
     *
     * Body: {
     *   "preco_original": 199.90,  // opcional, busca do ML se omitido
     *   "custos": { ... }  // opcional
     * }
     */
    public function getPromotionScenarios(string $itemId): void
    {
        $this->jsonResponse();
        $data = $this->getJsonInput();

        try {
            // Buscar dados do item se não informado
            $precoOriginal = $data['preco_original'] ?? null;
            if (!$precoOriginal) {
                $item = $this->mlClient->get("/items/{$itemId}");
                $precoOriginal = $item['price'] ?? 0;
            }

            // Buscar ou usar custos informados
            $custos = $data['custos'] ?? $this->marginService->getCustosProduto($itemId) ?? [];

            $cenarios = $this->promotionService->gerarCenarios($precoOriginal, $custos);

            echo json_encode([
                'success' => true,
                'item_id' => $itemId,
                'preco_original' => $precoOriginal,
                'cenarios' => $cenarios
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao gerar cenários: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/promotion/apply/:itemId
     * Aplica uma promoção no Mercado Livre
     *
     * Body: {
     *   "preco_promocional": 169.90,
     *   "motivo": "Promoção de verão"
     * }
     */
    public function applyPromotion(string $itemId): void
    {
        $this->jsonResponse();
        $data = $this->getJsonInput();

        if (!isset($data['preco_promocional'])) {
            $this->error('preco_promocional é obrigatório', 400);
            return;
        }

        try {
            $result = $this->promotionService->aplicarPromocao(
                $itemId,
                (float)$data['preco_promocional'],
                ['motivo' => $data['motivo'] ?? null]
            );
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao aplicar promoção: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/promotion/central-ofertas/:itemId
     * Simula participação na Central de Ofertas do ML
     *
     * Body: {
     *   "tipo_oferta": "deal_of_day"  // deal_of_day, lightning_deal, best_seller
     * }
     */
    public function simulateCentralOfertas(string $itemId): void
    {
        $this->jsonResponse();
        $data = $this->getJsonInput();

        $tipoOferta = $data['tipo_oferta'] ?? 'lightning_deal';

        try {
            $result = $this->promotionService->simularCentralOfertas($itemId, $tipoOferta);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao simular central de ofertas: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/promotion/history/:itemId?
     * Histórico de simulações de promoção
     */
    public function getPromotionHistory(string $itemId = null): void
    {
        $this->jsonResponse();

        $limit = $this->request->getInt('limit', 20);

        try {
            $historico = $this->promotionService->getHistoricoSimulacoes($itemId, $limit);
            echo json_encode([
                'success' => true,
                'item_id' => $itemId,
                'historico' => $historico
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao buscar histórico: ' . $e->getMessage());
        }
    }

    // ==================== CENÁRIOS DE PRECIFICAÇÃO ====================

    /**
     * GET /api/pricing/:accountId/scenarios/strategies/:itemId
     * Compara estratégias de precificação para um item
     */
    public function compareStrategies(string $itemId): void
    {
        $this->jsonResponse();

        try {
            $result = $this->scenarioService->compararEstrategias($itemId);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao comparar estratégias: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/scenarios/what-if/:itemId
     * Cria cenário "what-if" para análise
     *
     * Body: {
     *   "preco": 199.90,
     *   "custos": { "custo_producao": 90, "taxa_imposto": 12 },
     *   "elasticidade": -1.5
     * }
     */
    public function createWhatIfScenario(string $itemId): void
    {
        $this->jsonResponse();
        $data = $this->getJsonInput();

        try {
            $result = $this->scenarioService->criarCenario($itemId, $data);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao criar cenário: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/scenarios/strategies
     * Lista estratégias disponíveis
     */
    public function listStrategies(): void
    {
        $this->jsonResponse();
        echo json_encode($this->scenarioService->getEstrategiasDisponiveis());
    }

    // ==================== REGRAS DE PRECIFICAÇÃO ====================

    /**
     * POST /api/pricing/:accountId/rules
     * Cria uma regra de precificação automática
     *
     * Body: {
     *   "nome": "Competitivo Eletrônicos",
     *   "descricao": "Mantém preços competitivos em eletrônicos",
     *   "estrategia": "competitivo",
     *   "categoria": "MLB1648",
     *   "margem_minima": 10,
     *   "margem_alvo": 20,
     *   "execucao_automatica": false
     * }
     */
    public function createPricingRule(): void
    {
        $this->jsonResponse();
        $data = $this->getJsonInput();

        if (empty($data['nome'])) {
            $this->error('nome é obrigatório', 400);
            return;
        }

        try {
            $result = $this->scenarioService->criarRegraAutomatica($data);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao criar regra: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/rules
     * Lista regras de precificação
     */
    public function listPricingRules(): void
    {
        $this->jsonResponse();

        $apenasAtivas = $this->request->get('ativas') !== null;

        try {
            $result = $this->scenarioService->listarRegras($apenasAtivas);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao listar regras: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/rules/:ruleId/execute
     * Executa uma regra de precificação
     *
     * Body: {
     *   "aplicar": false  // true para aplicar, false para simular
     * }
     */
    public function executePricingRule(int $ruleId): void
    {
        $this->jsonResponse();
        $data = $this->getJsonInput();

        $aplicar = $data['aplicar'] ?? false;

        try {
            $result = $this->scenarioService->executarRegra($ruleId, $aplicar);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao executar regra: ' . $e->getMessage());
        }
    }

    /**
     * DELETE /api/pricing/:accountId/rules/:ruleId
     * Remove uma regra de precificação
     */
    public function deletePricingRule(int $ruleId): void
    {
        $this->jsonResponse();

        try {
            $stmt = $this->db->prepare("
                DELETE FROM pricing_rules
                WHERE id = :id AND account_id = :account_id
            ");
            $stmt->execute(['id' => $ruleId, 'account_id' => $this->accountId]);

            echo json_encode([
                'success' => $stmt->rowCount() > 0,
                'message' => $stmt->rowCount() > 0 ? 'Regra removida' : 'Regra não encontrada'
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao remover regra: ' . $e->getMessage());
        }
    }

    /**
     * PUT /api/pricing/:accountId/rules/:ruleId/toggle
     * Ativa/desativa uma regra
     */
    public function togglePricingRule(int $ruleId): void
    {
        $this->jsonResponse();

        try {
            $stmt = $this->db->prepare("
                UPDATE pricing_rules
                SET ativo = NOT ativo
                WHERE id = :id AND account_id = :account_id
            ");
            $stmt->execute(['id' => $ruleId, 'account_id' => $this->accountId]);

            // Buscar estado atual
            $stmt = $this->db->prepare("SELECT ativo FROM pricing_rules WHERE id = :id");
            $stmt->execute(['id' => $ruleId]);
            $ativo = $stmt->fetchColumn();

            echo json_encode([
                'success' => true,
                'ativo' => (bool)$ativo
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao alternar regra: ' . $e->getMessage());
        }
    }

    // ==================== ALERTAS DE RANKING ====================

    /**
     * POST /api/pricing/:accountId/alerts/analyze-batch
     * Analisa ranking de múltiplos itens
     *
     * Body: {
     *   "item_ids": ["MLB123", "MLB456"]
     * }
     */
    public function analyzeItemsBatch(): void
    {
        $this->jsonResponse();
        $data = $this->getJsonInput();

        if (empty($data['item_ids']) || !is_array($data['item_ids'])) {
            $this->error('item_ids deve ser um array de IDs', 400);
            return;
        }

        try {
            $result = $this->alertService->analyzeBatch($data['item_ids']);
            echo json_encode(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            $this->error('Erro na análise em lote: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/alerts/unresolved
     * Obtém alertas não resolvidos
     */
    public function getUnresolvedAlerts(): void
    {
        $this->jsonResponse();

        $limit = $this->request->getInt('limit', 50);

        try {
            $alerts = $this->alertService->getUnresolvedAlerts($limit);
            $stats = $this->alertService->getAlertStats($this->request->getInt('days', 30));

            echo json_encode([
                'success' => true,
                'alerts' => $alerts,
                'stats' => $stats
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao buscar alertas: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/alerts/mark-read
     * Marca alertas como lidos
     *
     * Body: {
     *   "alert_ids": [1, 2, 3]
     * }
     */
    public function markAlertsRead(): void
    {
        $this->jsonResponse();
        $data = $this->getJsonInput();

        if (empty($data['alert_ids']) || !is_array($data['alert_ids'])) {
            $this->error('alert_ids deve ser um array de IDs', 400);
            return;
        }

        try {
            $success = $this->alertService->markAlertsAsRead($data['alert_ids']);
            echo json_encode(['success' => $success]);
        } catch (\Throwable $e) {
            $this->error('Erro ao marcar alertas: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/alerts/:alertId/resolve
     * Resolve um alerta específico
     *
     * Body: {
     *   "resolution": "Preço ajustado para R$ 189,90"
     * }
     */
    public function resolveAlert(int $alertId): void
    {
        $this->jsonResponse();
        $data = $this->getJsonInput();

        try {
            $success = $this->alertService->resolveAlert(
                $alertId,
                $data['resolution'] ?? null
            );
            echo json_encode(['success' => $success]);
        } catch (\Throwable $e) {
            $this->error('Erro ao resolver alerta: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/alerts/item/:itemId
     * Obtém histórico de alertas de um item
     */
    public function getItemAlertHistory(string $itemId): void
    {
        $this->jsonResponse();

        $limit = $this->request->getInt('limit', 20);

        try {
            $history = $this->alertService->getItemAlertHistory($itemId, $limit);
            echo json_encode([
                'success' => true,
                'item_id' => $itemId,
                'history' => $history
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao buscar histórico: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/alerts/stats
     * Obtém estatísticas de alertas
     */
    public function getAlertStats(): void
    {
        $this->jsonResponse();

        $days = $this->request->getInt('days', 30);
        $days = max(1, min(365, $days));

        $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));

        try {
            // Distribuição de ranking por faixa (baseada no cache de concorrência)
            // Obs: isso alimenta o gráfico de distribuição na UI (Excelente/Bom/Atenção/Crítico).
            $rankingDistributionByType = [
                'excellent' => 0,
                'good' => 0,
                'warning' => 0,
                'danger' => 0,
            ];
            $rankingDistributionTotals = [
                'total_cached_items' => 0,
            ];

            try {
                $distStmt = $this->db->prepare("\n                    SELECT\n                        SUM(CASE WHEN percentil_preco >= 0 AND percentil_preco < 8 THEN 1 ELSE 0 END) as excellent,\n                        SUM(CASE WHEN percentil_preco >= 8 AND percentil_preco < 12 THEN 1 ELSE 0 END) as good,\n                        SUM(CASE WHEN percentil_preco >= 12 AND percentil_preco < 15 THEN 1 ELSE 0 END) as warning,\n                        SUM(CASE WHEN percentil_preco >= 15 THEN 1 ELSE 0 END) as danger,\n                        COUNT(*) as total_cached_items\n                    FROM competitor_pricing_cache\n                    WHERE account_id = :account_id\n                      AND percentil_preco IS NOT NULL\n                      AND atualizado_em >= :cutoff\n                ");
                $distStmt->execute([
                    'account_id' => $this->accountId,
                    'cutoff' => $cutoff,
                ]);
                $distRow = $distStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $rankingDistributionByType = [
                    'excellent' => (int)($distRow['excellent'] ?? 0),
                    'good' => (int)($distRow['good'] ?? 0),
                    'warning' => (int)($distRow['warning'] ?? 0),
                    'danger' => (int)($distRow['danger'] ?? 0),
                ];
                $rankingDistributionTotals = [
                    'total_cached_items' => (int)($distRow['total_cached_items'] ?? 0),
                ];
            } catch (\Throwable $e) {
                // Sem cache/tabela indisponível: mantém zeros.
            }

            $stmt = $this->db->prepare("
                SELECT
                    nivel,
                    COUNT(*) as total,
                    SUM(CASE WHEN resolvido = 1 THEN 1 ELSE 0 END) as resolved,
                    SUM(CASE WHEN resolvido = 0 THEN 1 ELSE 0 END) as pending
                FROM pricing_ranking_alerts
                WHERE account_id = :account_id
                  AND criado_em >= :cutoff
                GROUP BY nivel
            ");
            $stmt->execute([
                'account_id' => $this->accountId,
                'cutoff' => $cutoff,
            ]);
            $byLevel = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalsByType = [
                'excellent' => 0,
                'good' => 0,
                'warning' => 0,
                'danger' => 0,
            ];

            foreach ($byLevel as $row) {
                $nivel = $row['nivel'] ?? null;
                $total = isset($row['total']) ? (int)$row['total'] : 0;

                $type = match ($nivel) {
                    'vermelho' => 'danger',
                    'amarelo' => 'warning',
                    'verde' => 'excellent',
                    default => null,
                };

                if ($type !== null) {
                    $totalsByType[$type] += $total;
                }
            }

            $byType = [];
            foreach ($totalsByType as $type => $total) {
                $byType[] = ['alert_type' => $type, 'total' => (int)$total];
            }

            $stmt = $this->db->prepare("
                SELECT
                    COUNT(*) as total_alerts,
                    SUM(CASE WHEN resolvido = 0 THEN 1 ELSE 0 END) as pending_alerts,
                    AVG(TIMESTAMPDIFF(HOUR, criado_em, COALESCE(resolvido_em, NOW()))) as avg_resolution_hours
                FROM pricing_ranking_alerts
                WHERE account_id = :account_id
                  AND criado_em >= :cutoff
            ");
            $stmt->execute([
                'account_id' => $this->accountId,
                'cutoff' => $cutoff,
            ]);
            $totals = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'stats' => [
                    'by_type' => $byType,
                    'by_level' => $byLevel,
                    'totals' => $totals,
                    'ranking_distribution' => [
                        'by_type' => array_map(static function (string $type, int $total): array {
                            return ['alert_type' => $type, 'total' => $total];
                        }, array_keys($rankingDistributionByType), array_values($rankingDistributionByType)),
                        'totals' => $rankingDistributionTotals,
                    ],
                    'period_days' => $days,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao buscar estatísticas: ' . $e->getMessage());
        }
    }

    // ==================== EXPORTAÇÃO E RELATÓRIOS ====================

    /**
     * GET /api/pricing/:accountId/export/csv
     * Exporta dados de pricing em CSV
     */
    public function exportCsv(): void
    {
        try {
            // Buscar todos os produtos com custos
            $stmt = $this->db->prepare("
                SELECT
                    pc.item_id,
                    pc.sku,
                    pc.custo_producao,
                    pc.custo_embalagem,
                    pc.custo_etiqueta,
                    pc.custo_frete_gratis,
                    pc.taxa_comissao_ml,
                    pc.taxa_imposto,
                    pc.acos_medio,
                    pc.margem_minima,
                    pc.margem_alvo,
                    pc.atualizado_em
                FROM product_costs pc
                WHERE pc.account_id = :account_id
                ORDER BY pc.atualizado_em DESC
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Cabeçalho CSV
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="pricing_export_' . date('Y-m-d_His') . '.csv"');
            header('Pragma: no-cache');
            header('Expires: 0');

            $output = fopen('php://output', 'w');

            // BOM para Excel reconhecer UTF-8
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // Cabeçalho
            fputcsv($output, [
                'Item ID',
                'SKU',
                'Custo Produção',
                'Custo Embalagem',
                'Custo Etiqueta',
                'Custo Frete Grátis',
                'Taxa Comissão ML (%)',
                'Taxa Imposto (%)',
                'ACOS Médio (%)',
                'Margem Mínima (%)',
                'Margem Alvo (%)',
                'Última Atualização'
            ], ';');

            // Dados
            foreach ($items as $item) {
                fputcsv($output, [
                    $item['item_id'],
                    $item['sku'] ?? '',
                    number_format((float)$item['custo_producao'], 2, ',', ''),
                    number_format((float)$item['custo_embalagem'], 2, ',', ''),
                    number_format((float)$item['custo_etiqueta'], 2, ',', ''),
                    number_format((float)$item['custo_frete_gratis'], 2, ',', ''),
                    number_format((float)$item['taxa_comissao_ml'], 2, ',', ''),
                    number_format((float)$item['taxa_imposto'], 2, ',', ''),
                    number_format((float)$item['acos_medio'], 2, ',', ''),
                    number_format((float)$item['margem_minima'], 2, ',', ''),
                    number_format((float)$item['margem_alvo'], 2, ',', ''),
                    $item['atualizado_em']
                ], ';');
            }

            fclose($output);
        } catch (\Throwable $e) {
            $this->jsonResponse();
            $this->error('Erro ao exportar: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/export/history
     * Exporta histórico de alterações de preço
     */
    public function exportHistory(): void
    {
        try {
            $days = $this->request->getInt('days', 30);

            $stmt = $this->db->prepare("
                SELECT
                    ph.item_id,
                    ph.preco_anterior,
                    ph.preco_novo,
                    ph.percentual_mudanca,
                    ph.margem_nova,
                    ph.origem,
                    ph.alerta_ranking,
                    ph.data_mudanca
                FROM pricing_history ph
                WHERE ph.account_id = :account_id
                AND ph.data_mudanca >= DATE_SUB(NOW(), INTERVAL :days DAY)
                ORDER BY ph.data_mudanca DESC
            ");
            $stmt->bindValue('account_id', $this->accountId, \PDO::PARAM_INT);
            $stmt->bindValue('days', $days, \PDO::PARAM_INT);
            $stmt->execute();
            $history = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="pricing_history_' . date('Y-m-d_His') . '.csv"');

            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($output, [
                'Item ID',
                'Preço Anterior',
                'Preço Novo',
                'Variação (%)',
                'Nova Margem (%)',
                'Origem',
                'Alerta Ranking',
                'Data/Hora'
            ], ';');

            foreach ($history as $row) {
                fputcsv($output, [
                    $row['item_id'],
                    number_format((float)$row['preco_anterior'], 2, ',', ''),
                    number_format((float)$row['preco_novo'], 2, ',', ''),
                    number_format((float)$row['percentual_mudanca'], 2, ',', ''),
                    number_format((float)($row['margem_nova'] ?? 0), 2, ',', ''),
                    $row['origem'] ?? 'manual',
                    $row['alerta_ranking'] ? 'Sim' : 'Não',
                    $row['data_mudanca']
                ], ';');
            }

            fclose($output);
        } catch (\Throwable $e) {
            $this->jsonResponse();
            $this->error('Erro ao exportar histórico: ' . $e->getMessage());
        }
    }

    // ==================== ANÁLISE DE TENDÊNCIAS ====================

    /**
     * GET /api/pricing/:accountId/trends/:itemId
     * Obtém tendências de preço e margem de um item
     */
    public function getItemTrends(string $itemId): void
    {
        header('Content-Type: application/json');

        $days = $this->request->getInt('days', 30);

        try {
            // Histórico de preços
            $historico = [];
            try {
                $stmt = $this->db->prepare("
                    SELECT
                        DATE(data_mudanca) as data,
                        AVG(preco_novo) as preco_medio,
                        AVG(margem_nova) as margem_media,
                        COUNT(*) as alteracoes
                    FROM pricing_history
                    WHERE account_id = :account_id
                    AND item_id = :item_id
                    AND data_mudanca >= DATE_SUB(NOW(), INTERVAL :days DAY)
                    GROUP BY DATE(data_mudanca)
                    ORDER BY data ASC
                ");
                $stmt->bindValue('account_id', $this->accountId, \PDO::PARAM_INT);
                $stmt->bindValue('item_id', $itemId, \PDO::PARAM_STR);
                $stmt->bindValue('days', $days, \PDO::PARAM_INT);
                $stmt->execute();
                $historico = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e) {
                // Tabela pode não existir
            }

            // Dados de concorrência (se disponível)
            $concorrencia = [];
            try {
                $stmtConc = $this->db->prepare("
                    SELECT
                        DATE(atualizado_em) as data,
                        AVG(preco_minimo) as preco_min_conc,
                        AVG(preco_medio) as preco_med_conc,
                        AVG(preco_maximo) as preco_max_conc
                    FROM competitor_pricing_cache
                    WHERE account_id = :account_id
                    AND item_id = :item_id
                    AND atualizado_em >= DATE_SUB(NOW(), INTERVAL :days DAY)
                    GROUP BY DATE(atualizado_em)
                    ORDER BY data ASC
                ");
                $stmtConc->bindValue('account_id', $this->accountId, \PDO::PARAM_INT);
                $stmtConc->bindValue('item_id', $itemId, \PDO::PARAM_STR);
                $stmtConc->bindValue('days', $days, \PDO::PARAM_INT);
                $stmtConc->execute();
                $concorrencia = $stmtConc->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                // Tabela pode não existir ainda
            }

            // Calcular tendência
            $tendencia = $this->calcularTendencia($historico);

            // Valores mín/max
            $precoMinimo = !empty($historico) ? min(array_column($historico, 'preco_medio')) : 0;
            $precoMaximo = !empty($historico) ? max(array_column($historico, 'preco_medio')) : 0;

            echo json_encode([
                'success' => true,
                'item_id' => $itemId,
                'periodo_dias' => $days,
                'historico' => $historico,
                'concorrencia' => $concorrencia,
                'tendencia' => $tendencia['preco'],
                'volatilidade' => $tendencia['volatilidade'],
                'preco_minimo' => (float)$precoMinimo,
                'preco_maximo' => (float)$precoMaximo,
                'analise' => [
                    'tendencia_preco' => $tendencia['preco'],
                    'tendencia_margem' => $tendencia['margem'],
                    'volatilidade' => $tendencia['volatilidade'],
                    'total_alteracoes' => array_sum(array_column($historico, 'alteracoes'))
                ]
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao buscar tendências: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/metrics
     * Métricas avançadas de pricing
     */
    public function getAdvancedMetrics(): void
    {
        $this->jsonResponse();

        $days = $this->request->getInt('days', 30);

        try {
            // Total de produtos com custos cadastrados
            $stmtTotal = $this->db->prepare("
                SELECT COUNT(*) as total FROM product_costs WHERE account_id = :account_id
            ");
            $stmtTotal->execute(['account_id' => $this->accountId]);
            $totalProdutos = (int)$stmtTotal->fetchColumn();

            // Distribuição de margens (usando margem_alvo configurada pelo usuário)
            $stmtMargens = $this->db->prepare("
                SELECT
                    SUM(CASE WHEN margem_alvo < 5 THEN 1 ELSE 0 END) as critica,
                    SUM(CASE WHEN margem_alvo >= 5 AND margem_alvo < 10 THEN 1 ELSE 0 END) as baixa,
                    SUM(CASE WHEN margem_alvo >= 10 AND margem_alvo < 20 THEN 1 ELSE 0 END) as media,
                    SUM(CASE WHEN margem_alvo >= 20 THEN 1 ELSE 0 END) as boa
                FROM product_costs
                WHERE account_id = :account_id
            ");
            $stmtMargens->execute(['account_id' => $this->accountId]);
            $distribuicao = $stmtMargens->fetch(\PDO::FETCH_ASSOC);

            // Alterações de preço por período
            $alteracoes = ['total_alteracoes' => 0, 'aumentos' => 0, 'reducoes' => 0, 'com_alerta' => 0, 'variacao_media' => 0];
            try {
                $stmtAlteracoes = $this->db->prepare("
                    SELECT
                        COUNT(*) as total_alteracoes,
                        SUM(CASE WHEN percentual_mudanca > 0 THEN 1 ELSE 0 END) as aumentos,
                        SUM(CASE WHEN percentual_mudanca < 0 THEN 1 ELSE 0 END) as reducoes,
                        SUM(CASE WHEN alerta_ranking IN ('amarelo', 'vermelho') THEN 1 ELSE 0 END) as com_alerta,
                        AVG(ABS(percentual_mudanca)) as variacao_media
                    FROM pricing_history
                    WHERE account_id = :account_id
                    AND data_mudanca >= DATE_SUB(NOW(), INTERVAL :days DAY)
                ");
                $stmtAlteracoes->bindValue('account_id', $this->accountId, \PDO::PARAM_INT);
                $stmtAlteracoes->bindValue('days', $days, \PDO::PARAM_INT);
                $stmtAlteracoes->execute();
                $alteracoes = $stmtAlteracoes->fetch(\PDO::FETCH_ASSOC) ?: $alteracoes;
            } catch (\Throwable $e) {
                // Se tabela não existir, continua com valores default
            }

            // Alertas ativos
            $alertas = ['total' => 0, 'criticos' => 0, 'altos' => 0, 'medios' => 0, 'nao_lidos' => 0];
            try {
                $stmtAlertas = $this->db->prepare("
                    SELECT
                        COUNT(*) as total,
                        SUM(CASE WHEN nivel = 'vermelho' THEN 1 ELSE 0 END) as criticos,
                        SUM(CASE WHEN nivel = 'amarelo' THEN 1 ELSE 0 END) as altos,
                        SUM(CASE WHEN nivel = 'verde' THEN 1 ELSE 0 END) as medios,
                        SUM(CASE WHEN lido = 0 THEN 1 ELSE 0 END) as nao_lidos
                    FROM pricing_ranking_alerts
                    WHERE account_id = :account_id
                    AND resolvido = 0
                ");
                $stmtAlertas->execute(['account_id' => $this->accountId]);
                $alertas = $stmtAlertas->fetch(\PDO::FETCH_ASSOC) ?: $alertas;
            } catch (\Throwable $e) {
                // Se tabela não existir, continua com valores default
            }

            // Regras ativas
            $regras = ['total' => 0, 'ativas' => 0];
            try {
                $stmtRegras = $this->db->prepare("
                    SELECT
                        COUNT(*) as total,
                        SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END) as ativas
                    FROM pricing_rules
                    WHERE account_id = :account_id
                ");
                $stmtRegras->execute(['account_id' => $this->accountId]);
                $regras = $stmtRegras->fetch(\PDO::FETCH_ASSOC) ?: $regras;
            } catch (\Throwable $e) {
                // Se tabela não existir, continua com valores default
            }

            // Top 5 produtos com mais alterações
            $topAlteracoes = [];
            try {
                $stmtTop = $this->db->prepare("
                    SELECT
                        item_id,
                        COUNT(*) as alteracoes,
                        AVG(percentual_mudanca) as variacao_media
                    FROM pricing_history
                    WHERE account_id = :account_id
                    AND data_mudanca >= DATE_SUB(NOW(), INTERVAL :days DAY)
                    GROUP BY item_id
                    ORDER BY alteracoes DESC
                    LIMIT 5
                ");
                $stmtTop->bindValue('account_id', $this->accountId, \PDO::PARAM_INT);
                $stmtTop->bindValue('days', $days, \PDO::PARAM_INT);
                $stmtTop->execute();
                $topAlteracoes = $stmtTop->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                // Se tabela não existir, continua com valores default
            }

            // Tendência dos últimos 7 dias (por dia)
            $tendencia = [];
            try {
                $stmtTendencia = $this->db->prepare("
                    SELECT
                        DATE(data_mudanca) as data,
                        COUNT(*) as alteracoes,
                        SUM(CASE WHEN percentual_mudanca > 0 THEN 1 ELSE 0 END) as aumentos,
                        SUM(CASE WHEN percentual_mudanca < 0 THEN 1 ELSE 0 END) as reducoes
                    FROM pricing_history
                    WHERE account_id = :account_id
                    AND data_mudanca >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    GROUP BY DATE(data_mudanca)
                    ORDER BY data ASC
                ");
                $stmtTendencia->execute(['account_id' => $this->accountId]);
                $tendencia = $stmtTendencia->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                // Se tabela não existir, continua com valores default
            }

            // Formatar tendência para o gráfico
            $tendencia7dias = [];
            $dataAtual = new \DateTime();
            for ($i = 6; $i >= 0; $i--) {
                $dataCheck = (clone $dataAtual)->modify("-{$i} days")->format('Y-m-d');
                $dataLabel = (clone $dataAtual)->modify("-{$i} days")->format('d/m');
                $found = array_filter($tendencia, fn($t) => $t['data'] === $dataCheck);
                $tendencia7dias[] = [
                    'data' => $dataLabel,
                    'alteracoes' => !empty($found) ? (int)array_values($found)[0]['alteracoes'] : 0,
                    'aumentos' => !empty($found) ? (int)array_values($found)[0]['aumentos'] : 0,
                    'reducoes' => !empty($found) ? (int)array_values($found)[0]['reducoes'] : 0
                ];
            }

            // Calcular margem média a partir dos custos
            $stmtMargemMedia = $this->db->prepare("
                SELECT AVG(margem_alvo) as media FROM product_costs WHERE account_id = :account_id
            ");
            $stmtMargemMedia->execute(['account_id' => $this->accountId]);
            $margemMedia = (float)($stmtMargemMedia->fetchColumn() ?? 0);

            echo json_encode([
                'success' => true,
                'periodo_dias' => $days,
                // Formato simplificado para o frontend
                'distribuicao' => [
                    'critica' => (int)($distribuicao['critica'] ?? 0),
                    'baixa' => (int)($distribuicao['baixa'] ?? 0),
                    'media' => (int)($distribuicao['media'] ?? 0),
                    'boa' => (int)($distribuicao['boa'] ?? 0),
                    'sem_custos' => 0 // Calculado na listagem
                ],
                'margens' => [
                    'media' => round($margemMedia, 2)
                ],
                'lucro_potencial_mensal' => 0, // Seria necessário cálculo mais complexo
                'alteracoes_7_dias' => (int)($alteracoes['total_alteracoes'] ?? 0),
                'alertas_pendentes' => (int)($alertas['nao_lidos'] ?? 0),
                'tendencia_7_dias' => $tendencia7dias,
                // Dados detalhados também disponíveis
                'metricas' => [
                    'produtos' => [
                        'total' => $totalProdutos,
                        'distribuicao_margem' => [
                            'critica' => (int)($distribuicao['critica'] ?? 0),
                            'baixa' => (int)($distribuicao['baixa'] ?? 0),
                            'media' => (int)($distribuicao['media'] ?? 0),
                            'boa' => (int)($distribuicao['boa'] ?? 0)
                        ]
                    ],
                    'alteracoes' => [
                        'total' => (int)($alteracoes['total_alteracoes'] ?? 0),
                        'aumentos' => (int)($alteracoes['aumentos'] ?? 0),
                        'reducoes' => (int)($alteracoes['reducoes'] ?? 0),
                        'com_alerta' => (int)($alteracoes['com_alerta'] ?? 0),
                        'variacao_media' => round((float)($alteracoes['variacao_media'] ?? 0), 2)
                    ],
                    'alertas' => [
                        'total' => (int)($alertas['total'] ?? 0),
                        'criticos' => (int)($alertas['criticos'] ?? 0),
                        'altos' => (int)($alertas['altos'] ?? 0),
                        'medios' => (int)($alertas['medios'] ?? 0),
                        'nao_lidos' => (int)($alertas['nao_lidos'] ?? 0)
                    ],
                    'regras' => [
                        'total' => (int)($regras['total'] ?? 0),
                        'ativas' => (int)($regras['ativas'] ?? 0)
                    ],
                    'top_alteracoes' => $topAlteracoes
                ]
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao buscar métricas: ' . $e->getMessage());
        }
    }

    // ==================== AÇÕES EM LOTE ====================

    /**
     * POST /api/pricing/:accountId/bulk/apply-rule
     * Aplica uma regra a múltiplos itens
     *
     * Body: {
     *   "item_ids": ["MLB123", "MLB456"],
     *   "simulate": true
     * }
     */
    public function bulkApplyRule(): void
    {
        $this->jsonResponse();
        $data = $this->getJsonInput();

        if (empty($data['item_ids'])) {
            $this->error('item_ids é obrigatório', 400);
            return;
        }

        $simulate = $data['simulate'] ?? true;

        try {
            $rulesService = new PricingRulesService($this->accountId);
            $results = [];
            $successCount = 0;
            $errorCount = 0;

            foreach ($data['item_ids'] as $itemId) {
                try {
                    // Buscar custos do produto
                    $custos = $this->marginService->getCustosProduto($itemId);

                    if (!$custos) {
                        $results[] = [
                            'item_id' => $itemId,
                            'success' => false,
                            'error' => 'Custos não cadastrados'
                        ];
                        $errorCount++;
                        continue;
                    }

                    // Buscar preço atual do ML
                    $item = $this->mlClient->get("/items/{$itemId}");
                    $precoAtual = (float)($item['price'] ?? 0);

                    if ($precoAtual <= 0) {
                        $results[] = [
                            'item_id' => $itemId,
                            'success' => false,
                            'error' => 'Não foi possível obter preço atual'
                        ];
                        $errorCount++;
                        continue;
                    }

                    // Aplicar regras
                    $result = $rulesService->applyRules($itemId, $precoAtual, [
                        'category_id' => $item['category_id'] ?? null,
                        'custos' => $custos
                    ]);

                    // Se não é simulação e há mudança de preço, aplicar
                    if (!$simulate && $result['recommended_price'] != $precoAtual) {
                        $this->mlClient->put("/items/{$itemId}", [
                            'price' => $result['recommended_price']
                        ]);
                    }

                    $results[] = [
                        'item_id' => $itemId,
                        'success' => true,
                        'preco_atual' => $precoAtual,
                        'preco_recomendado' => $result['recommended_price'],
                        'variacao_percentual' => $result['price_change_percent'],
                        'regras_aplicadas' => count($result['rules_applied']),
                        'violacoes' => count($result['violations'])
                    ];
                    $successCount++;
                } catch (\Throwable $e) {
                    $results[] = [
                        'item_id' => $itemId,
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                    $errorCount++;
                }
            }

            echo json_encode([
                'success' => true,
                'simulated' => $simulate,
                'total' => count($data['item_ids']),
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'results' => $results
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao aplicar regras em lote: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/bulk/update-costs
     * Atualiza custos de múltiplos itens com base em percentual
     *
     * Body: {
     *   "item_ids": ["MLB123", "MLB456"],
     *   "field": "custo_producao",
     *   "adjustment_type": "percent", // ou "absolute"
     *   "adjustment_value": 5
     * }
     */
    public function bulkUpdateCosts(): void
    {
        $this->jsonResponse();
        $data = $this->getJsonInput();

        $validFields = [
            'custo_producao',
            'custo_embalagem',
            'custo_etiqueta',
            'custo_frete_gratis',
            'taxa_comissao_ml',
            'taxa_imposto',
            'acos_medio',
            'margem_minima',
            'margem_alvo'
        ];

        if (empty($data['item_ids']) || !isset($data['field']) || !isset($data['adjustment_value'])) {
            $this->error('item_ids, field e adjustment_value são obrigatórios', 400);
            return;
        }

        if (!in_array($data['field'], $validFields)) {
            $this->error('Campo inválido: ' . $data['field'], 400);
            return;
        }

        $adjustmentType = $data['adjustment_type'] ?? 'percent';
        $adjustmentValue = (float)$data['adjustment_value'];

        try {
            $updated = 0;

            foreach ($data['item_ids'] as $itemId) {
                if ($adjustmentType === 'percent') {
                    $sql = "UPDATE product_costs
                            SET {$data['field']} = {$data['field']} * (1 + :adj/100),
                                atualizado_em = NOW()
                            WHERE account_id = :account_id AND item_id = :item_id";
                } else {
                    $sql = "UPDATE product_costs
                            SET {$data['field']} = {$data['field']} + :adj,
                                atualizado_em = NOW()
                            WHERE account_id = :account_id AND item_id = :item_id";
                }

                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    'adj' => $adjustmentValue,
                    'account_id' => $this->accountId,
                    'item_id' => $itemId
                ]);
                $updated += $stmt->rowCount();
            }

            echo json_encode([
                'success' => true,
                'updated' => $updated,
                'field' => $data['field'],
                'adjustment' => ($adjustmentType === 'percent' ? '+' : '') . $adjustmentValue . ($adjustmentType === 'percent' ? '%' : '')
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao atualizar custos em lote: ' . $e->getMessage());
        }
    }

    // ==================== HELPERS PRIVADOS ====================

    /**
     * Calcula tendência de preço e margem
     */
    private function calcularTendencia(array $historico): array
    {
        if (count($historico) < 2) {
            return ['preco' => 'estavel', 'margem' => 'estavel', 'volatilidade' => 'baixa'];
        }

        $precos = array_column($historico, 'preco_medio');
        $margens = array_column($historico, 'margem_media');

        // Tendência de preço
        $primeiroPreco = (float)$precos[0];
        $ultimoPreco = (float)end($precos);
        $variacaoPreco = $primeiroPreco > 0 ? (($ultimoPreco - $primeiroPreco) / $primeiroPreco) * 100 : 0;

        $tendenciaPreco = 'estavel';
        if ($variacaoPreco > 5) {
            $tendenciaPreco = 'alta';
        } elseif ($variacaoPreco < -5) {
            $tendenciaPreco = 'baixa';
        }

        // Tendência de margem
        $primeiraMargem = (float)$margens[0];
        $ultimaMargem = (float)end($margens);
        $variacaoMargem = $ultimaMargem - $primeiraMargem;

        $tendenciaMargem = 'estavel';
        if ($variacaoMargem > 2) {
            $tendenciaMargem = 'melhorando';
        } elseif ($variacaoMargem < -2) {
            $tendenciaMargem = 'piorando';
        }

        // Volatilidade
        $diferencas = [];
        for ($i = 1; $i < count($precos); $i++) {
            $diferencas[] = abs((float)$precos[$i] - (float)$precos[$i - 1]);
        }
        $mediaPreco = array_sum($precos) / count($precos);
        $volatilidade = $mediaPreco > 0 ? (array_sum($diferencas) / count($diferencas)) / $mediaPreco * 100 : 0;

        $nivelVolatilidade = 'baixa';
        if ($volatilidade > 10) {
            $nivelVolatilidade = 'alta';
        } elseif ($volatilidade > 5) {
            $nivelVolatilidade = 'media';
        }

        return [
            'preco' => $tendenciaPreco,
            'margem' => $tendenciaMargem,
            'volatilidade' => $nivelVolatilidade
        ];
    }

    /**
     * Preview de itens para interface quando ?preview=true
     * Prioriza dados reais do banco local (ml_items + product_costs).
     */
    private function getPreviewItems(int $page, int $limit): array
    {
        $page = max(1, (int)$page);
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, min(1000000, ($page - 1) * $limit));

        try {
            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM ml_items WHERE account_id = :account_id");
            $countStmt->execute(['account_id' => $this->accountId]);
            $total = (int)$countStmt->fetchColumn();

            $stmt = $this->db->prepare("
                SELECT
                    m.id,
                    m.title,
                    m.sku,
                    m.price,
                    m.status,
                    m.category_id,
                    m.available_quantity,
                    m.sold_quantity,
                    m.thumbnail,
                    pc.custo_producao,
                    pc.custo_embalagem,
                    pc.custo_etiqueta,
                    pc.custo_frete_gratis,
                    pc.taxa_comissao_ml,
                    pc.taxa_imposto,
                    pc.acos_medio,
                    pc.margem_minima,
                    pc.margem_alvo
                FROM ml_items m
                LEFT JOIN product_costs pc
                    ON pc.account_id = m.account_id
                   AND pc.item_id = m.id
                WHERE m.account_id = :account_id
                ORDER BY m.updated_at DESC
                LIMIT {$limit} OFFSET {$offset}
            ");
            $stmt->bindValue('account_id', $this->accountId, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $items = [];
            foreach ($rows as $row) {
                $margem = null;
                $lucro = null;
                $indicador = 'cinza';

                $hasCosts = $row['custo_producao'] !== null
                    || $row['custo_embalagem'] !== null
                    || $row['custo_etiqueta'] !== null
                    || $row['custo_frete_gratis'] !== null;

                if ($hasCosts) {
                    $custos = [
                        'custo_producao' => (float)($row['custo_producao'] ?? 0),
                        'custo_embalagem' => (float)($row['custo_embalagem'] ?? 0),
                        'custo_etiqueta' => (float)($row['custo_etiqueta'] ?? 0),
                        'custo_frete_gratis' => (float)($row['custo_frete_gratis'] ?? 0),
                        'taxa_comissao_ml' => (float)($row['taxa_comissao_ml'] ?? 0),
                        'taxa_imposto' => (float)($row['taxa_imposto'] ?? 0),
                        'acos_medio' => (float)($row['acos_medio'] ?? 0),
                        'margem_minima' => (float)($row['margem_minima'] ?? 0),
                        'margem_alvo' => (float)($row['margem_alvo'] ?? 0),
                    ];

                    $calc = $this->marginService->calcularMargem((float)$row['price'], $custos);
                    $margem = $calc['margem_real'] ?? null;
                    $lucro = $calc['lucro_unitario'] ?? null;
                    $indicador = $calc['indicador'] ?? 'cinza';
                }

                $items[] = [
                    'id' => $row['id'],
                    'titulo' => $row['title'],
                    'sku' => $row['sku'],
                    'preco' => (float)$row['price'],
                    'status' => $row['status'] ?? 'active',
                    'categoria' => $row['category_id'],
                    'tipo_anuncio' => null,
                    'estoque' => (int)($row['available_quantity'] ?? 0),
                    'vendidos' => (int)($row['sold_quantity'] ?? 0),
                    'thumbnail' => $row['thumbnail'],
                    'margem' => $margem,
                    'lucro_unitario' => $lucro !== null ? round((float)$lucro, 2) : null,
                    'indicador' => $indicador,
                    'custos_cadastrados' => $hasCosts,
                ];
            }

            if (empty($items)) {
                return [
                    'success' => true,
                    'page' => $page,
                    'limit' => $limit,
                    'total' => 0,
                    'items' => [],
                    'preview_mode' => true,
                    'aviso' => 'Nenhum item local disponível para preview. Sincronize os itens para visualizar dados reais.'
                ];
            }

            return [
                'success' => true,
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'items' => $items,
                'preview_mode' => true,
                'aviso' => 'Preview com dados reais locais (ml_items/product_costs).'
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'page' => $page,
                'limit' => $limit,
                'total' => 0,
                'items' => [],
                'preview_mode' => true,
                'error' => 'Não foi possível montar preview real de itens.',
                'details' => $e->getMessage(),
            ];
        }
    }

    /**
     * GET /api/pricing-intelligence/:accountId/performance
     * Relatório de performance de precificação
     */
    public function getPerformanceReport(): void
    {
        $this->jsonResponse();

        try {
            $days = $this->request->getInt('days', 30);
            $startDate = date('Y-m-d', strtotime("-{$days} days"));

            // Métricas gerais
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(DISTINCT item_id) as total_items_alterados,
                    COUNT(*) as total_alteracoes,
                    AVG(percentual_mudanca) as variacao_media,
                    SUM(CASE WHEN percentual_mudanca > 0 THEN 1 ELSE 0 END) as aumentos,
                    SUM(CASE WHEN percentual_mudanca < 0 THEN 1 ELSE 0 END) as reducoes,
                    AVG(margem_nova) as margem_media_nova
                FROM pricing_history
                WHERE account_id = :account_id
                AND data_mudanca >= :start_date
            ");
            $stmt->execute(['account_id' => $this->accountId, 'start_date' => $startDate]);
            $metricas = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Evolução diária
            $stmt = $this->db->prepare("
                SELECT
                    DATE(data_mudanca) as data,
                    COUNT(*) as alteracoes,
                    AVG(percentual_mudanca) as variacao_media,
                    AVG(margem_nova) as margem_media
                FROM pricing_history
                WHERE account_id = :account_id
                AND data_mudanca >= :start_date
                GROUP BY DATE(data_mudanca)
                ORDER BY data ASC
            ");
            $stmt->execute(['account_id' => $this->accountId, 'start_date' => $startDate]);
            $evolucao = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Top 10 itens com mais alterações
            $stmt = $this->db->prepare("
                SELECT
                    item_id,
                    COUNT(*) as total_alteracoes,
                    MIN(preco_anterior) as preco_min,
                    MAX(preco_novo) as preco_max,
                    AVG(percentual_mudanca) as variacao_media
                FROM pricing_history
                WHERE account_id = :account_id
                AND data_mudanca >= :start_date
                GROUP BY item_id
                ORDER BY total_alteracoes DESC
                LIMIT 10
            ");
            $stmt->execute(['account_id' => $this->accountId, 'start_date' => $startDate]);
            $topItems = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Alertas gerados
            $stmt = $this->db->prepare("
                SELECT
                    SUM(CASE WHEN nivel_alerta = 'vermelho' THEN 1 ELSE 0 END) as alertas_criticos,
                    SUM(CASE WHEN nivel_alerta = 'amarelo' THEN 1 ELSE 0 END) as alertas_moderados,
                    SUM(CASE WHEN nivel_alerta = 'verde' THEN 1 ELSE 0 END) as alertas_leves,
                    SUM(CASE WHEN resolvido = 1 THEN 1 ELSE 0 END) as resolvidos
                FROM pricing_ranking_alerts
                WHERE account_id = :account_id
                AND criado_em >= :start_date
            ");
            $stmt->execute(['account_id' => $this->accountId, 'start_date' => $startDate]);
            $alertas = $stmt->fetch(\PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'periodo' => [
                    'dias' => $days,
                    'inicio' => $startDate,
                    'fim' => date('Y-m-d')
                ],
                'metricas' => [
                    'total_items_alterados' => (int)($metricas['total_items_alterados'] ?? 0),
                    'total_alteracoes' => (int)($metricas['total_alteracoes'] ?? 0),
                    'variacao_media' => round((float)($metricas['variacao_media'] ?? 0), 2),
                    'aumentos' => (int)($metricas['aumentos'] ?? 0),
                    'reducoes' => (int)($metricas['reducoes'] ?? 0),
                    'margem_media_nova' => round((float)($metricas['margem_media_nova'] ?? 0), 2)
                ],
                'evolucao_diaria' => $evolucao,
                'top_items' => $topItems,
                'alertas' => [
                    'criticos' => (int)($alertas['alertas_criticos'] ?? 0),
                    'moderados' => (int)($alertas['alertas_moderados'] ?? 0),
                    'leves' => (int)($alertas['alertas_leves'] ?? 0),
                    'resolvidos' => (int)($alertas['resolvidos'] ?? 0)
                ]
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao gerar relatório: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing-intelligence/:accountId/auto-suggest/{itemId}
     * Sugere preço otimizado baseado em análise de dados
     */
    public function autoSuggestPrice(string $itemId): void
    {
        $this->jsonResponse();

        try {
            // Buscar dados do item
            $itemData = $this->mlClient->get("/items/{$itemId}");
            if (!$itemData || isset($itemData['error'])) {
                $this->error('Item não encontrado no Mercado Livre');
                return;
            }

            $precoAtual = (float)$itemData['price'];
            $categoryId = $itemData['category_id'];

            // Buscar custos cadastrados
            $custos = $this->marginService->getCustosProduto($itemId);
            if (!$custos) {
                $this->error('Custos não cadastrados para este item. Cadastre os custos primeiro.');
                return;
            }

            // Análise de concorrentes
            $concorrentes = [];
            try {
                $searchResult = $this->mlClient->get("/sites/MLB/search", [
                    'category' => $categoryId,
                    'limit' => 20,
                    'sort' => 'relevance'
                ]);

                if ($searchResult && isset($searchResult['results'])) {
                    foreach ($searchResult['results'] as $item) {
                        if ($item['id'] !== $itemId) {
                            $concorrentes[] = [
                                'preco' => $item['price'],
                                'vendidos' => $item['sold_quantity'] ?? 0
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Ignorar erro de concorrentes
            }

            // Calcular preço médio dos concorrentes
            $precoMedioConcorrentes = 0;
            if (!empty($concorrentes)) {
                $precoMedioConcorrentes = array_sum(array_column($concorrentes, 'preco')) / count($concorrentes);
            }

            // Calcular preço mínimo (margem 5%)
            $precoMinimoResult = $this->marginService->calcularPrecoMinimo($custos, 5);
            $precoMinimo = $precoMinimoResult['preco_minimo'] ?? 0;

            // Calcular preço ideal (margem alvo ou 15%)
            $margemAlvo = (float)($custos['margem_alvo'] ?? 15);
            $precoIdealResult = $this->marginService->calcularPrecoMinimo($custos, $margemAlvo);
            $precoIdeal = $precoIdealResult['preco_minimo'] ?? 0;

            // Estratégias de preço
            $estrategias = [];

            // 1. Preço competitivo (5% abaixo da média)
            if ($precoMedioConcorrentes > 0) {
                $precoCompetitivo = $precoMedioConcorrentes * 0.95;
                $margemCompetitivo = $this->marginService->calcularMargem($precoCompetitivo, $custos);

                $estrategias['competitivo'] = [
                    'preco' => round($precoCompetitivo, 2),
                    'margem' => $margemCompetitivo['margem_real'],
                    'lucro' => $margemCompetitivo['lucro_unitario'],
                    'descricao' => '5% abaixo da média dos concorrentes',
                    'viavel' => $margemCompetitivo['margem_real'] >= 0
                ];
            }

            // 2. Preço de margem mínima
            if ($precoMinimo > 0) {
                $margemMinimoCalc = $this->marginService->calcularMargem($precoMinimo, $custos);
                $estrategias['margem_minima'] = [
                    'preco' => round($precoMinimo, 2),
                    'margem' => 5.0,
                    'lucro' => $margemMinimoCalc['lucro_unitario'],
                    'descricao' => 'Preço mínimo para margem de 5%',
                    'viavel' => true
                ];
            }

            // 3. Preço de margem ideal
            if ($precoIdeal > 0) {
                $margemIdealCalc = $this->marginService->calcularMargem($precoIdeal, $custos);
                $estrategias['margem_ideal'] = [
                    'preco' => round($precoIdeal, 2),
                    'margem' => $margemAlvo,
                    'lucro' => $margemIdealCalc['lucro_unitario'],
                    'descricao' => "Preço para margem alvo de {$margemAlvo}%",
                    'viavel' => true
                ];
            }

            // 4. Preço Premium (+10% sobre média)
            if ($precoMedioConcorrentes > 0) {
                $precoPremium = $precoMedioConcorrentes * 1.10;
                $margemPremium = $this->marginService->calcularMargem($precoPremium, $custos);

                $estrategias['premium'] = [
                    'preco' => round($precoPremium, 2),
                    'margem' => $margemPremium['margem_real'],
                    'lucro' => $margemPremium['lucro_unitario'],
                    'descricao' => '10% acima da média dos concorrentes',
                    'viavel' => true
                ];
            }

            // Recomendação
            $recomendacao = 'margem_ideal';
            if ($precoIdeal > 0 && $precoAtual > $precoIdeal * 1.15) {
                $recomendacao = 'margem_ideal';
            } elseif ($precoMinimo > 0 && $precoAtual < $precoMinimo) {
                $recomendacao = 'margem_minima';
            } elseif ($precoMedioConcorrentes > 0 && $precoAtual > $precoMedioConcorrentes * 1.2) {
                $recomendacao = 'competitivo';
            }

            echo json_encode([
                'success' => true,
                'item_id' => $itemId,
                'preco_atual' => $precoAtual,
                'margem_atual' => $this->marginService->calcularMargem($precoAtual, $custos)['margem_real'],
                'preco_medio_concorrentes' => round($precoMedioConcorrentes, 2),
                'total_concorrentes' => count($concorrentes),
                'estrategias' => $estrategias,
                'recomendacao' => $recomendacao,
                'analise' => [
                    'posicao_atual' => $precoAtual <= $precoMedioConcorrentes ? 'competitivo' : 'acima_mercado',
                    'gap_concorrencia' => $precoMedioConcorrentes > 0
                        ? round((($precoAtual - $precoMedioConcorrentes) / $precoMedioConcorrentes) * 100, 2)
                        : null
                ]
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao sugerir preço: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing-intelligence/:accountId/monitor/competitors/{itemId}
     * Monitora preços dos concorrentes para um item específico
     */
    public function monitorCompetitors(string $itemId): void
    {
        $this->jsonResponse();

        try {
            $limit = $this->request->getIntClamped('limit', 1, 50, 50);
            $q = $this->request->get('q');
            $q = is_string($q) && trim($q) !== '' ? trim($q) : null;

            $service = new PricingCompetitorMonitorService(
                accountId: $this->accountId,
                db: $this->db,
                mlClient: $this->mlClient
            );

            $result = $service->monitorItem($itemId, $limit, $q);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao monitorar concorrentes: ' . $e->getMessage());
        }
    }

    /**
     * Extrai palavras-chave de um título
     */
    private function extractKeywords(string $titulo): array
    {
        $stopwords = ['de', 'da', 'do', 'para', 'com', 'em', 'e', 'a', 'o', 'as', 'os', 'um', 'uma'];
        $words = preg_split('/\s+/', mb_strtolower($titulo));
        $keywords = array_filter($words, fn($w) => strlen($w) > 2 && !in_array($w, $stopwords));
        return array_values(array_slice($keywords, 0, 5));
    }

    /**
     * POST /api/pricing-intelligence/:accountId/forecast
     * Previsão de margem baseada em cenários
     */
    public function forecastMargin(): void
    {
        $this->jsonResponse();
        $data = $this->getJsonInput();

        try {
            $itemId = $data['item_id'] ?? null;
            $cenarios = $data['cenarios'] ?? [];

            if (!$itemId) {
                $this->error('item_id é obrigatório');
                return;
            }

            // Buscar custos
            $custos = $this->marginService->getCustosProduto($itemId);
            if (!$custos) {
                $this->error('Custos não cadastrados para este item');
                return;
            }

            // Buscar preço atual
            $itemData = $this->mlClient->get("/items/{$itemId}");
            $precoAtual = (float)($itemData['price'] ?? 0);

            // Se não há cenários, criar cenários padrão
            if (empty($cenarios)) {
                $cenarios = [
                    ['nome' => 'Redução 10%', 'variacao_preco' => -10],
                    ['nome' => 'Redução 5%', 'variacao_preco' => -5],
                    ['nome' => 'Atual', 'variacao_preco' => 0],
                    ['nome' => 'Aumento 5%', 'variacao_preco' => 5],
                    ['nome' => 'Aumento 10%', 'variacao_preco' => 10],
                    ['nome' => 'Aumento 15%', 'variacao_preco' => 15],
                ];
            }

            $previsoes = [];
            foreach ($cenarios as $cenario) {
                $nome = $cenario['nome'] ?? 'Cenário';
                $variacaoPreco = (float)($cenario['variacao_preco'] ?? 0);
                $variacaoCusto = (float)($cenario['variacao_custo'] ?? 0);

                // Calcular novo preço
                $novoPreco = $precoAtual * (1 + $variacaoPreco / 100);

                // Ajustar custos se necessário
                $custosAjustados = $custos;
                if ($variacaoCusto !== 0) {
                    $custosAjustados['custo_producao'] = $custos['custo_producao'] * (1 + $variacaoCusto / 100);
                }

                // Calcular margem
                $resultado = $this->marginService->calcularMargem($novoPreco, $custosAjustados);

                // Impacto no ranking (estimado)
                $impactoRanking = 'neutro';
                if ($variacaoPreco > 12) {
                    $impactoRanking = 'negativo_alto';
                } elseif ($variacaoPreco > 8) {
                    $impactoRanking = 'negativo_moderado';
                } elseif ($variacaoPreco < -5) {
                    $impactoRanking = 'positivo';
                }

                $previsoes[] = [
                    'cenario' => $nome,
                    'variacao_preco' => $variacaoPreco,
                    'variacao_custo' => $variacaoCusto,
                    'preco_novo' => round($novoPreco, 2),
                    'margem' => $resultado['margem_real'],
                    'lucro_unitario' => $resultado['lucro_unitario'],
                    'indicador' => $resultado['indicador'],
                    'impacto_ranking' => $impactoRanking,
                    'viavel' => $resultado['margem_real'] > 0
                ];
            }

            // Encontrar cenário ideal (maior margem viável sem impacto negativo alto)
            $cenarioIdeal = null;
            $maiorMargem = -PHP_FLOAT_MAX;
            foreach ($previsoes as $p) {
                if ($p['viavel'] && $p['impacto_ranking'] !== 'negativo_alto' && $p['margem'] > $maiorMargem) {
                    $maiorMargem = $p['margem'];
                    $cenarioIdeal = $p['cenario'];
                }
            }

            echo json_encode([
                'success' => true,
                'item_id' => $itemId,
                'preco_atual' => $precoAtual,
                'margem_atual' => $this->marginService->calcularMargem($precoAtual, $custos)['margem_real'],
                'previsoes' => $previsoes,
                'cenario_recomendado' => $cenarioIdeal
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro na previsão: ' . $e->getMessage());
        }
    }

    /**
     * Configura alerta de preço para um item
     * POST /api/pricing-intelligence/{accountId}/price-alerts
     */
    public function createPriceAlert(): void
    {
        header('Content-Type: application/json');

        try {
            $input = $this->request->json();

            $itemId = $input['item_id'] ?? null;
            $tipoAlerta = $input['tipo'] ?? 'concorrente_abaixo'; // concorrente_abaixo, margem_minima, ranking_perdido
            $valorGatilho = $input['valor_gatilho'] ?? 5; // percentual ou valor absoluto
            $notificarEmail = $input['notificar_email'] ?? true;
            $notificarWhatsapp = $input['notificar_whatsapp'] ?? false;

            if (!$itemId) {
                $this->error('item_id é obrigatório');
                return;
            }

            // Buscar item no ML para validar
            $item = $this->mlClient->get("/items/{$itemId}");
            if (!$item) {
                $this->error('Item não encontrado');
                return;
            }

            // Inserir alerta
            $stmt = $this->db->prepare("
                INSERT INTO pricing_price_alerts
                (account_id, item_id, tipo_alerta, valor_gatilho, notificar_email, notificar_whatsapp, ativo, created_at)
                VALUES (:account_id, :item_id, :tipo, :valor, :email, :whatsapp, 1, NOW())
                ON DUPLICATE KEY UPDATE
                valor_gatilho = VALUES(valor_gatilho),
                notificar_email = VALUES(notificar_email),
                notificar_whatsapp = VALUES(notificar_whatsapp),
                ativo = 1,
                updated_at = NOW()
            ");

            $stmt->execute([
                'account_id' => $this->accountId,
                'item_id' => $itemId,
                'tipo' => $tipoAlerta,
                'valor' => $valorGatilho,
                'email' => $notificarEmail ? 1 : 0,
                'whatsapp' => $notificarWhatsapp ? 1 : 0
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Alerta configurado com sucesso',
                'alerta' => [
                    'item_id' => $itemId,
                    'titulo' => $item['title'] ?? $itemId,
                    'tipo' => $tipoAlerta,
                    'valor_gatilho' => $valorGatilho,
                    'notificacoes' => [
                        'email' => $notificarEmail,
                        'whatsapp' => $notificarWhatsapp
                    ]
                ]
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao criar alerta: ' . $e->getMessage());
        }
    }

    /**
     * Lista alertas de preço configurados
     * GET /api/pricing-intelligence/{accountId}/price-alerts
     */
    public function listPriceAlerts(): void
    {
        header('Content-Type: application/json');

        try {
            // Verificar se tabela existe
            $tableExists = $this->db->query("SHOW TABLES LIKE 'pricing_price_alerts'")->rowCount() > 0;

            if (!$tableExists) {
                // Criar tabela se não existir
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS pricing_price_alerts (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        account_id INT NOT NULL,
                        item_id VARCHAR(50) NOT NULL,
                        tipo_alerta ENUM('concorrente_abaixo', 'margem_minima', 'ranking_perdido', 'preco_alterado') NOT NULL,
                        valor_gatilho DECIMAL(10,2) NOT NULL,
                        notificar_email TINYINT(1) DEFAULT 1,
                        notificar_whatsapp TINYINT(1) DEFAULT 0,
                        ativo TINYINT(1) DEFAULT 1,
                        ultima_verificacao DATETIME,
                        ultima_notificacao DATETIME,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY unique_alerta (account_id, item_id, tipo_alerta),
                        KEY idx_account (account_id),
                        KEY idx_ativo (ativo)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");

                echo json_encode([
                    'success' => true,
                    'alertas' => [],
                    'total' => 0
                ]);
                return;
            }

            $stmt = $this->db->prepare("
                SELECT a.*,
                       (SELECT COUNT(*) FROM pricing_alert_history WHERE alert_id = a.id) as total_disparos
                FROM pricing_price_alerts a
                WHERE a.account_id = :account_id
                ORDER BY a.created_at DESC
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $alertas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Enriquecer com dados do item
            foreach ($alertas as &$alerta) {
                $item = $this->mlClient->get("/items/{$alerta['item_id']}");
                $alerta['titulo'] = $item['title'] ?? 'Produto';
                $alerta['preco_atual'] = $item['price'] ?? 0;
                $alerta['thumbnail'] = $item['thumbnail'] ?? '';
            }

            echo json_encode([
                'success' => true,
                'alertas' => $alertas,
                'total' => count($alertas)
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao listar alertas: ' . $e->getMessage());
        }
    }

    /**
     * Remove alerta de preço
     * DELETE /api/pricing-intelligence/{accountId}/price-alerts/{alertId}
     */
    public function deletePriceAlert(string $alertId): void
    {
        header('Content-Type: application/json');

        try {
            $stmt = $this->db->prepare("
                DELETE FROM pricing_price_alerts
                WHERE id = :id AND account_id = :account_id
            ");
            $stmt->execute([
                'id' => $alertId,
                'account_id' => $this->accountId
            ]);

            if ($stmt->rowCount() > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Alerta removido'
                ]);
            } else {
                $this->error('Alerta não encontrado', 404);
            }
        } catch (\Throwable $e) {
            $this->error('Erro ao remover alerta: ' . $e->getMessage());
        }
    }

    /**
     * Importa custos de produtos via CSV
     * POST /api/pricing-intelligence/{accountId}/import/costs
     */
    public function importCosts(): void
    {
        header('Content-Type: application/json');

        try {
            $uploadedFile = $this->request->file('file');
            if (!$uploadedFile) {
                $this->error('Arquivo não enviado ou inválido');
                return;
            }

            $file = $uploadedFile['tmp_name'];
            $handle = fopen($file, 'r');

            if (!$handle) {
                $this->error('Não foi possível abrir o arquivo');
                return;
            }

            // Pular header
            $header = fgetcsv($handle, 0, ',');

            // Validar colunas mínimas
            $requiredColumns = ['item_id', 'custo_produto'];
            $headerLower = array_map('strtolower', $header);

            foreach ($requiredColumns as $col) {
                if (!in_array($col, $headerLower)) {
                    $this->error("Coluna obrigatória ausente: $col");
                    fclose($handle);
                    return;
                }
            }

            $importados = 0;
            $erros = [];

            $stmt = $this->db->prepare("
                INSERT INTO product_costs
                (account_id, item_id, custo_produto, custo_frete, imposto_percentual, taxa_ml_percentual, custo_fixo, updated_at)
                VALUES (:account_id, :item_id, :custo, :frete, :imposto, :taxa, :fixo, NOW())
                ON DUPLICATE KEY UPDATE
                custo_produto = VALUES(custo_produto),
                custo_frete = VALUES(custo_frete),
                imposto_percentual = VALUES(imposto_percentual),
                taxa_ml_percentual = VALUES(taxa_ml_percentual),
                custo_fixo = VALUES(custo_fixo),
                updated_at = NOW()
            ");

            $linha = 1;
            while (($data = fgetcsv($handle, 0, ',')) !== false) {
                $linha++;

                try {
                    $row = array_combine($headerLower, $data);

                    $itemId = trim($row['item_id'] ?? '');
                    if (empty($itemId)) {
                        $erros[] = "Linha $linha: item_id vazio";
                        continue;
                    }

                    $stmt->execute([
                        'account_id' => $this->accountId,
                        'item_id' => $itemId,
                        'custo' => floatval(str_replace(',', '.', $row['custo_produto'] ?? 0)),
                        'frete' => floatval(str_replace(',', '.', $row['custo_frete'] ?? 0)),
                        'imposto' => floatval(str_replace(',', '.', $row['imposto_percentual'] ?? 0)),
                        'taxa' => floatval(str_replace(',', '.', $row['taxa_ml_percentual'] ?? 16)),
                        'fixo' => floatval(str_replace(',', '.', $row['custo_fixo'] ?? 0))
                    ]);

                    $importados++;
                } catch (\Throwable $e) {
                    $erros[] = "Linha $linha: " . $e->getMessage();
                }
            }

            fclose($handle);

            echo json_encode([
                'success' => true,
                'message' => "Importação concluída",
                'importados' => $importados,
                'erros' => count($erros),
                'detalhes_erros' => array_slice($erros, 0, 10)
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro na importação: ' . $e->getMessage());
        }
    }

    /**
     * Calcula preço ideal baseado em meta de margem
     * POST /api/pricing-intelligence/{accountId}/calculate-ideal-price
     */
    public function calculateIdealPrice(): void
    {
        header('Content-Type: application/json');

        try {
            $input = $this->request->json();

            $itemId = $input['item_id'] ?? null;
            $margemDesejada = $input['margem_desejada'] ?? 20;

            if (!$itemId) {
                $this->error('item_id é obrigatório');
                return;
            }

            // Buscar custos usando marginService
            $custos = $this->marginService->getCustosProduto($itemId);
            if (!$custos) {
                $this->error('Custos não cadastrados para este item');
                return;
            }

            // Buscar item atual usando mlClient
            $item = $this->mlClient->get("/items/{$itemId}");
            $precoAtual = $item['price'] ?? 0;

            // Calcular preço ideal
            // Fórmula: Preço = Custo Total / (1 - (Margem% + Taxa%) / 100)
            $custoTotal = ($custos['custo_producao'] ?? 0) + ($custos['custo_frete_gratis'] ?? 0) + ($custos['custo_embalagem'] ?? 0);
            $taxaTotal = ($custos['taxa_comissao_ml'] ?? 16) + ($custos['taxa_imposto'] ?? 0);

            $divisor = 1 - (($margemDesejada + $taxaTotal) / 100);

            if ($divisor <= 0) {
                $this->error('Margem desejada muito alta para os custos atuais');
                return;
            }

            $precoIdeal = $custoTotal / $divisor;

            // Buscar concorrentes para contexto
            $categoryId = $item['category_id'] ?? null;
            $precoMedioConcorrentes = null;

            if ($categoryId) {
                try {
                    $concorrentes = $this->strategyService->analyzeCompetitorPrices($categoryId);
                    $precoMedioConcorrentes = $concorrentes['statistics']['preco_medio'] ?? null;
                } catch (\Throwable $e) {
                    // Ignorar erro de concorrentes
                }
            }

            // Análise
            $diferencaAtual = $precoAtual > 0 ? (($precoIdeal - $precoAtual) / $precoAtual) * 100 : 0;
            $competitivo = $precoMedioConcorrentes ? $precoIdeal <= $precoMedioConcorrentes * 1.1 : null;

            echo json_encode([
                'success' => true,
                'item_id' => $itemId,
                'titulo' => $item['title'] ?? 'Produto',
                'preco_atual' => round($precoAtual, 2),
                'preco_ideal' => round($precoIdeal, 2),
                'margem_desejada' => $margemDesejada,
                'diferenca_percentual' => round($diferencaAtual, 2),
                'custos' => [
                    'produto' => $custos['custo_produto'],
                    'frete' => $custos['custo_frete'],
                    'fixo' => $custos['custo_fixo'],
                    'total' => $custoTotal,
                    'taxa_ml' => $custos['taxa_ml_percentual'],
                    'imposto' => $custos['imposto_percentual']
                ],
                'analise' => [
                    'preco_medio_concorrentes' => $precoMedioConcorrentes ? round($precoMedioConcorrentes, 2) : null,
                    'competitivo' => $competitivo,
                    'acao_sugerida' => $diferencaAtual > 5 ? 'aumentar_preco' : ($diferencaAtual < -5 ? 'reduzir_preco' : 'manter_preco')
                ]
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao calcular preço ideal: ' . $e->getMessage());
        }
    }

    /**
     * Analisa rentabilidade geral da conta
     * GET /api/pricing-intelligence/{accountId}/profitability
     */
    public function analyzeProfitability(): void
    {
        header('Content-Type: application/json');

        try {
            // Buscar todos os custos cadastrados
            $stmt = $this->db->prepare("
                SELECT * FROM product_costs
                WHERE account_id = :account_id
            ");
            $stmt->execute(['account_id' => $this->accountId]);
            $custos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($custos)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Nenhum custo cadastrado',
                    'itens_analisados' => 0
                ]);
                return;
            }

            $analises = [];
            $totais = [
                'itens' => 0,
                'lucrativos' => 0,
                'prejuizo' => 0,
                'lucro_total_estimado' => 0
            ];

            foreach ($custos as $custo) {
                $item = $this->mlClient->get("/items/{$custo['item_id']}");
                if (!$item || !isset($item['price'])) {
                    continue;
                }

                $preco = $item['price'];
                $resultado = $this->marginService->calcularMargem($preco, $custo);

                $totais['itens']++;

                if ($resultado['margem_real'] >= 0) {
                    $totais['lucrativos']++;
                } else {
                    $totais['prejuizo']++;
                }

                // Estimar lucro mensal (baseado em sold_quantity se disponível)
                $vendidosMes = $item['sold_quantity'] ?? 1;
                $lucroMensal = $resultado['lucro_unitario'] * $vendidosMes;
                $totais['lucro_total_estimado'] += $lucroMensal;

                $custoTotal = ($custo['custo_producao'] ?? 0) + ($custo['custo_frete_gratis'] ?? 0) + ($custo['custo_embalagem'] ?? 0);

                $analises[] = [
                    'item_id' => $custo['item_id'],
                    'titulo' => $item['title'] ?? 'Produto',
                    'preco' => $preco,
                    'custo_total' => $custoTotal,
                    'margem' => $resultado['margem_real'],
                    'lucro_unitario' => $resultado['lucro_unitario'],
                    'vendidos_mes' => $vendidosMes,
                    'lucro_mensal_estimado' => round($lucroMensal, 2),
                    'status' => $resultado['indicador']
                ];
            }

            // Ordenar por lucro mensal (decrescente)
            usort($analises, fn($a, $b) => $b['lucro_mensal_estimado'] <=> $a['lucro_mensal_estimado']);

            echo json_encode([
                'success' => true,
                'resumo' => [
                    'total_itens' => $totais['itens'],
                    'itens_lucrativos' => $totais['lucrativos'],
                    'itens_prejuizo' => $totais['prejuizo'],
                    'percentual_lucrativos' => $totais['itens'] > 0 ? round(($totais['lucrativos'] / $totais['itens']) * 100, 1) : 0,
                    'lucro_mensal_estimado' => round($totais['lucro_total_estimado'], 2)
                ],
                'top_lucrativos' => array_slice(array_filter($analises, fn($a) => $a['margem'] >= 0), 0, 10),
                'prejuizo' => array_filter($analises, fn($a) => $a['margem'] < 0),
                'analises' => $analises
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro na análise de rentabilidade: ' . $e->getMessage());
        }
    }

    // ==================== AUTO-OTIMIZADOR DE PREÇOS ====================

    /**
     * Obtém configuração do auto-otimizador
     * GET /api/pricing-intelligence/{accountId}/auto-optimizer/config
     */
    public function getAutoOptimizerConfig(): void
    {
        header('Content-Type: application/json');

        try {
            $optimizer = new AutoPricingOptimizerService($this->accountId);
            $config = $optimizer->getConfig();

            echo json_encode([
                'success' => true,
                'config' => $config
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter configuração: ' . $e->getMessage());
        }
    }

    /**
     * Salva configuração do auto-otimizador
     * POST /api/pricing-intelligence/{accountId}/auto-optimizer/config
     */
    public function saveAutoOptimizerConfig(): void
    {
        header('Content-Type: application/json');

        try {
            $input = $this->request->json();

            $optimizer = new AutoPricingOptimizerService($this->accountId);
            $result = $optimizer->saveConfig($input);

            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao salvar configuração: ' . $e->getMessage());
        }
    }

    /**
     * Executa otimização manual
     * POST /api/pricing-intelligence/{accountId}/auto-optimizer/run
     */
    public function runAutoOptimizer(): void
    {
        header('Content-Type: application/json');

        try {
            $optimizer = new AutoPricingOptimizerService($this->accountId);
            $result = $optimizer->runOptimization();

            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro na otimização: ' . $e->getMessage());
        }
    }

    /**
     * Analisa um item específico para otimização
     * GET /api/pricing-intelligence/{accountId}/auto-optimizer/analyze/{itemId}
     */
    public function analyzeItemForOptimization(string $itemId): void
    {
        header('Content-Type: application/json');

        try {
            // Buscar item do ML
            $item = $this->mlClient->get("/items/{$itemId}");

            if (!$item) {
                $this->error('Item não encontrado');
                return;
            }

            $optimizer = new AutoPricingOptimizerService($this->accountId);
            $analysis = $optimizer->analyzeItem($item);

            echo json_encode([
                'success' => true,
                'item_id' => $itemId,
                'title' => $item['title'] ?? '',
                'analysis' => $analysis
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro na análise: ' . $e->getMessage());
        }
    }

    /**
     * Obtém estatísticas do otimizador
     * GET /api/pricing-intelligence/{accountId}/auto-optimizer/stats
     */
    public function getAutoOptimizerStats(): void
    {
        header('Content-Type: application/json');

        try {
            $optimizer = new AutoPricingOptimizerService($this->accountId);
            $stats = $optimizer->getStats();

            echo json_encode([
                'success' => true,
                'stats' => $stats
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter estatísticas: ' . $e->getMessage());
        }
    }

    /**
     * Obtém histórico de otimizações
     * GET /api/pricing-intelligence/{accountId}/auto-optimizer/history
     */
    public function getAutoOptimizerHistory(): void
    {
        header('Content-Type: application/json');

        try {
            $days = $this->request->getInt('days', 30);
            $limit = $this->request->getIntClamped('limit', 1, 500, 100);

            $optimizer = new AutoPricingOptimizerService($this->accountId);
            $history = $optimizer->getOptimizationHistory($days, $limit);

            echo json_encode([
                'success' => true,
                'history' => $history,
                'total' => count($history)
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter histórico: ' . $e->getMessage());
        }
    }

    /**
     * Aplica sugestão de preço do otimizador
     * POST /api/pricing-intelligence/{accountId}/auto-optimizer/apply/{itemId}
     */
    public function applyOptimizerSuggestion(string $itemId): void
    {
        header('Content-Type: application/json');

        try {
            $input = $this->request->json();
            $newPrice = (float)($input['price'] ?? 0);

            if ($newPrice <= 0) {
                $this->error('Preço inválido');
                return;
            }

            // Aplicar preço no ML
            $response = $this->mlClient->put("/items/{$itemId}", [
                'price' => $newPrice
            ]);

            if ($response && isset($response['id'])) {
                // Registrar no histórico
                $stmt = $this->db->prepare("
                    INSERT INTO pricing_history
                    (account_id, item_id, preco_novo, motivo, created_at)
                    VALUES (:account_id, :item_id, :preco, 'auto_optimizer_manual', NOW())
                ");
                $stmt->execute([
                    'account_id' => $this->accountId,
                    'item_id' => $itemId,
                    'preco' => $newPrice
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Preço aplicado com sucesso',
                    'new_price' => $newPrice
                ]);
            } else {
                $this->error('Erro ao aplicar preço no Mercado Livre');
            }
        } catch (\Throwable $e) {
            $this->error('Erro ao aplicar preço: ' . $e->getMessage());
        }
    }

    // ========================================
    // Métodos de Teste A/B de Preços
    // ========================================

    /**
     * Lista testes A/B
     */
    public function listAbTests(): void
    {
        try {
            $service = new \App\Services\PriceAbTestService($this->accountId);

            $filters = [];
            if (!empty($this->request->get('status'))) {
                $filters['status'] = $this->request->get('status');
            }
            if (!empty($this->request->get('item_id'))) {
                $filters['item_id'] = $this->request->get('item_id');
            }
            $filters['limit'] = $this->request->getInt('limit', 50);

            $tests = $service->listTests($filters);

            echo json_encode([
                'success' => true,
                'tests' => $tests,
                'count' => count($tests)
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao listar testes: ' . $e->getMessage());
        }
    }

    /**
     * Cria um novo teste A/B
     */
    public function createAbTest(): void
    {
        try {
            $data = $this->request->json() ?? [];

            $service = new \App\Services\PriceAbTestService($this->accountId);
            $result = $service->createTest($data);

            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao criar teste: ' . $e->getMessage());
        }
    }

    /**
     * Obtém detalhes de um teste A/B
     */
    public function getAbTest(int $testId): void
    {
        try {
            $service = new \App\Services\PriceAbTestService($this->accountId);
            $test = $service->getTest($testId);

            if (!$test) {
                $this->error('Teste não encontrado', 404);
                return;
            }

            echo json_encode([
                'success' => true,
                'test' => $test
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter teste: ' . $e->getMessage());
        }
    }

    /**
     * Inicia um teste A/B
     */
    public function startAbTest(int $testId): void
    {
        try {
            $service = new \App\Services\PriceAbTestService($this->accountId);
            $result = $service->startTest($testId);

            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao iniciar teste: ' . $e->getMessage());
        }
    }

    /**
     * Pausa um teste A/B
     */
    public function pauseAbTest(int $testId): void
    {
        try {
            $service = new \App\Services\PriceAbTestService($this->accountId);
            $result = $service->pauseTest($testId);

            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao pausar teste: ' . $e->getMessage());
        }
    }

    /**
     * Finaliza um teste A/B
     */
    public function completeAbTest(int $testId): void
    {
        try {
            $data = $this->request->json() ?? [];
            $winner = $data['winner'] ?? null;

            $service = new \App\Services\PriceAbTestService($this->accountId);
            $result = $service->completeTest($testId, $winner);

            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao finalizar teste: ' . $e->getMessage());
        }
    }

    /**
     * Cancela um teste A/B
     */
    public function cancelAbTest(int $testId): void
    {
        try {
            $service = new \App\Services\PriceAbTestService($this->accountId);
            $result = $service->cancelTest($testId);

            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao cancelar teste: ' . $e->getMessage());
        }
    }

    /**
     * Analisa resultados de um teste A/B
     */
    public function analyzeAbTest(int $testId): void
    {
        try {
            $service = new \App\Services\PriceAbTestService($this->accountId);
            $analysis = $service->analyzeTest($testId);

            echo json_encode($analysis);
        } catch (\Throwable $e) {
            $this->error('Erro ao analisar teste: ' . $e->getMessage());
        }
    }

    /**
     * Obtém resultados diários de um teste A/B
     */
    public function getAbTestResults(int $testId): void
    {
        try {
            $service = new \App\Services\PriceAbTestService($this->accountId);

            $results = $service->getResults($testId);
            $dailyResults = $service->getDailyResults($testId);

            echo json_encode([
                'success' => true,
                'summary' => $results,
                'daily' => $dailyResults
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter resultados: ' . $e->getMessage());
        }
    }

    /**
     * Registra resultados de um teste A/B
     */
    public function recordAbTestResults(int $testId): void
    {
        try {
            $data = $this->request->json() ?? [];

            if (empty($data['variant']) || !in_array($data['variant'], ['control', 'variant'])) {
                $this->error('Variante inválida');
                return;
            }

            $service = new \App\Services\PriceAbTestService($this->accountId);
            $result = $service->recordResults($testId, $data['variant'], $data);

            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao registrar resultados: ' . $e->getMessage());
        }
    }

    /**
     * Obtém log de um teste A/B
     */
    public function getAbTestLog(int $testId): void
    {
        try {
            $service = new \App\Services\PriceAbTestService($this->accountId);
            $log = $service->getTestLog($testId);

            echo json_encode([
                'success' => true,
                'log' => $log
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter log: ' . $e->getMessage());
        }
    }

    /**
     * Obtém estatísticas de testes A/B
     */
    public function getAbTestStats(): void
    {
        try {
            $service = new \App\Services\PriceAbTestService($this->accountId);
            $stats = $service->getStats();

            echo json_encode([
                'success' => true,
                'stats' => $stats
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter estatísticas: ' . $e->getMessage());
        }
    }

    // ========================================
    // Métodos de Monitoramento de Concorrentes
    // ========================================

    /**
     * Obtém watchlist de monitoramento
     */
    public function getWatchlist(): void
    {
        try {
            $service = new \App\Services\CompetitorMonitorService($this->accountId);
            $watchlist = $service->getWatchlist();

            echo json_encode([
                'success' => true,
                'watchlist' => $watchlist
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter watchlist: ' . $e->getMessage());
        }
    }

    /**
     * Adiciona item à watchlist
     */
    public function addToWatchlist(): void
    {
        try {
            $data = $this->request->json() ?? [];

            $service = new \App\Services\CompetitorMonitorService($this->accountId);
            $result = $service->addToWatchlist($data);

            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao adicionar à watchlist: ' . $e->getMessage());
        }
    }

    /**
     * Remove item da watchlist
     */
    public function removeFromWatchlist(string $itemId): void
    {
        try {
            $service = new \App\Services\CompetitorMonitorService($this->accountId);
            $result = $service->removeFromWatchlist($itemId);

            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao remover da watchlist: ' . $e->getMessage());
        }
    }

    /**
     * Escaneia concorrentes para um item
     */
    public function scanCompetitors(string $itemId): void
    {
        try {
            $keywords = $this->request->get('keywords');

            $service = new \App\Services\CompetitorMonitorService($this->accountId);
            $result = $service->scanCompetitors($itemId, $keywords);

            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao escanear concorrentes: ' . $e->getMessage());
        }
    }

    /**
     * Obtém análise de mercado para um item
     */
    public function getMarketAnalysis(string $itemId): void
    {
        try {
            $service = new \App\Services\CompetitorMonitorService($this->accountId);
            $analysis = $service->getMarketAnalysis($itemId);

            echo json_encode($analysis);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter análise de mercado: ' . $e->getMessage());
        }
    }

    /**
     * Obtém alertas de mercado
     */
    public function getMarketAlerts(): void
    {
        try {
            $filters = [
                'is_read' => $this->request->get('is_read') !== null ? $this->request->getBool('is_read') : null,
                'item_id' => $this->request->get('item_id'),
                'severity' => $this->request->get('severity'),
                'limit' => $this->request->getInt('limit', 50)
            ];

            $service = new \App\Services\CompetitorMonitorService($this->accountId);
            $alerts = $service->getAlerts(array_filter($filters, fn($v) => $v !== null));

            echo json_encode([
                'success' => true,
                'alerts' => $alerts
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter alertas: ' . $e->getMessage());
        }
    }

    /**
     * Marca alertas de mercado como lidos
     */
    public function markMarketAlertsAsRead(): void
    {
        try {
            $data = $this->request->json() ?? [];
            $alertIds = $data['alert_ids'] ?? [];

            $service = new \App\Services\CompetitorMonitorService($this->accountId);
            $result = $service->markAlertsAsRead($alertIds);

            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao marcar alertas: ' . $e->getMessage());
        }
    }

    /**
     * Obtém estatísticas de monitoramento
     */
    public function getMonitoringStats(): void
    {
        try {
            $service = new \App\Services\CompetitorMonitorService($this->accountId);
            $stats = $service->getStats();

            echo json_encode([
                'success' => true,
                'stats' => $stats
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter estatísticas: ' . $e->getMessage());
        }
    }

    // =====================================================
    // PHASE 3: PRICING RULES ENGINE (Advanced)
    // =====================================================

    /**
     * POST /api/pricing/:accountId/rules-engine/create
     * Cria uma regra de precificação automática avançada
     */
    public function createEngineRule(): void
    {
        try {
            $data = $this->request->json() ?? [];
            $service = new \App\Services\PriceRulesEngineService($this->accountId);
            $result = $service->createRule($data);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao criar regra: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/rules-engine
     * Lista todas as regras do motor avançado
     */
    public function listEngineRules(): void
    {
        try {
            $filters = [
                'type' => $this->request->get('type'),
                'is_active' => $this->request->get('is_active') !== null ? $this->request->getBool('is_active') : null
            ];
            $service = new \App\Services\PriceRulesEngineService($this->accountId);
            $result = $service->listRules(array_filter($filters, fn($v) => $v !== null));
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao listar regras: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/rules-engine/:ruleId
     * Obtém detalhes de uma regra do motor
     */
    public function getEngineRule(int $ruleId): void
    {
        try {
            $service = new \App\Services\PriceRulesEngineService($this->accountId);
            $result = $service->getRule($ruleId);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter regra: ' . $e->getMessage());
        }
    }

    /**
     * PUT /api/pricing/:accountId/rules-engine/:ruleId
     * Atualiza uma regra do motor
     */
    public function updateEngineRule(int $ruleId): void
    {
        try {
            $data = $this->request->json() ?? [];
            $service = new \App\Services\PriceRulesEngineService($this->accountId);
            $result = $service->updateRule($ruleId, $data);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao atualizar regra: ' . $e->getMessage());
        }
    }

    /**
     * DELETE /api/pricing/:accountId/rules-engine/:ruleId
     * Exclui uma regra do motor
     */
    public function deleteEngineRule(int $ruleId): void
    {
        try {
            $service = new \App\Services\PriceRulesEngineService($this->accountId);
            $result = $service->deleteRule($ruleId);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao excluir regra: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/rules-engine/:ruleId/toggle
     * Ativa/desativa uma regra do motor
     */
    public function toggleEngineRule(int $ruleId): void
    {
        try {
            $data = $this->request->json() ?? [];
            $active = $data['active'] ?? true;
            $service = new \App\Services\PriceRulesEngineService($this->accountId);
            $result = $service->toggleRule($ruleId, $active);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao alternar regra: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/rules-engine/execute/:itemId
     * Executa regras do motor para um item específico
     */
    public function executeEngineRulesForItem(string $itemId): void
    {
        try {
            $data = $this->request->json() ?? [];
            $apply = $data['apply'] ?? false;
            $service = new \App\Services\PriceRulesEngineService($this->accountId);
            $result = $service->executeRulesForItem($itemId, $apply);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao executar regras: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/rules-engine/execute-all
     * Executa todas as regras ativas do motor
     */
    public function executeAllEngineRules(): void
    {
        try {
            $data = $this->request->json() ?? [];
            $apply = $data['apply'] ?? false;
            $limit = $data['limit'] ?? 100;
            $service = new \App\Services\PriceRulesEngineService($this->accountId);
            $result = $service->executeAllRules($apply, $limit);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao executar regras: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/rules-engine/simulate
     * Simula execução de regras do motor sem aplicar
     */
    public function simulateEngineRules(): void
    {
        try {
            $data = $this->request->json() ?? [];
            $itemIds = $data['item_ids'] ?? [];
            $ruleIds = $data['rule_ids'] ?? null;
            $service = new \App\Services\PriceRulesEngineService($this->accountId);
            $result = $service->simulateRules($itemIds, $ruleIds);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao simular regras: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/rules-engine/templates
     * Obtém templates de regras do motor
     */
    public function getEngineRuleTemplates(): void
    {
        try {
            $service = new \App\Services\PriceRulesEngineService($this->accountId);
            $result = $service->getRuleTemplates();
            echo json_encode(['success' => true, 'templates' => $result]);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter templates: ' . $e->getMessage());
        }
    }

    // =====================================================
    // PHASE 3: SCHEDULED PRICES
    // =====================================================

    /**
     * POST /api/pricing/:accountId/schedules/create
     * Cria um agendamento de preço
     */
    public function createSchedule(): void
    {
        try {
            $data = $this->request->json() ?? [];
            $service = new \App\Services\ScheduledPriceService($this->accountId);
            $result = $service->createSchedule($data);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao criar agendamento: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/schedules/campaign
     * Cria uma campanha de preços
     */
    public function createCampaign(): void
    {
        try {
            $data = $this->request->json() ?? [];
            $service = new \App\Services\ScheduledPriceService($this->accountId);
            $result = $service->createCampaign($data);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao criar campanha: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/schedules
     * Lista todos os agendamentos
     */
    public function listSchedules(): void
    {
        try {
            $filters = [
                'status' => $this->request->get('status'),
                'item_id' => $this->request->get('item_id'),
                'campaign_id' => $this->request->get('campaign_id') !== null ? $this->request->getInt('campaign_id') : null
            ];
            $service = new \App\Services\ScheduledPriceService($this->accountId);
            $result = $service->listSchedules(array_filter($filters, fn($v) => $v !== null));
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao listar agendamentos: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/schedules/campaigns
     * Lista todas as campanhas
     */
    public function listCampaigns(): void
    {
        try {
            $filters = [
                'status' => $this->request->get('status')
            ];
            $service = new \App\Services\ScheduledPriceService($this->accountId);
            $result = $service->listCampaigns(array_filter($filters, fn($v) => $v !== null));
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao listar campanhas: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/schedules/:scheduleId/cancel
     * Cancela um agendamento
     */
    public function cancelSchedule(int $scheduleId): void
    {
        try {
            $service = new \App\Services\ScheduledPriceService($this->accountId);
            $result = $service->cancelSchedule($scheduleId);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao cancelar agendamento: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/schedules/campaign/:campaignId/cancel
     * Cancela uma campanha
     */
    public function cancelCampaign(int $campaignId): void
    {
        try {
            $service = new \App\Services\ScheduledPriceService($this->accountId);
            $result = $service->cancelCampaign($campaignId);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao cancelar campanha: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/schedules/calendar
     * Obtém calendário de agendamentos
     */
    public function getScheduleCalendar(): void
    {
        try {
            $startDate = $this->request->get('start_date', date('Y-m-01'));
            $endDate = $this->request->get('end_date', date('Y-m-t'));
            $service = new \App\Services\ScheduledPriceService($this->accountId);
            $result = $service->getCalendar($startDate, $endDate);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter calendário: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/schedules/summary
     * Obtém resumo de agendamentos
     */
    public function getScheduleSummary(): void
    {
        try {
            $service = new \App\Services\ScheduledPriceService($this->accountId);
            $result = $service->getSummary();
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter resumo: ' . $e->getMessage());
        }
    }

    // =====================================================
    // PHASE 3: PRICE ANALYTICS
    // =====================================================

    /**
     * GET /api/pricing/:accountId/analytics/dashboard
     * Obtém métricas do dashboard de analytics
     */
    public function getAnalyticsDashboard(): void
    {
        try {
            $period = $this->request->get('period', '30d');
            $service = new \App\Services\PriceAnalyticsService($this->accountId);
            $result = $service->getDashboardMetrics($period);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter dashboard: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/analytics/trend/:itemId
     * Obtém tendência de preços de um item
     */
    public function getPriceTrend(string $itemId): void
    {
        try {
            $days = $this->request->getInt('days', 30);
            $service = new \App\Services\PriceAnalyticsService($this->accountId);
            $result = $service->getPriceTrend($itemId, $days);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter tendência: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/analytics/elasticity/:itemId
     * Analisa elasticidade de preço de um item
     */
    public function analyzeElasticity(string $itemId): void
    {
        try {
            $service = new \App\Services\PriceAnalyticsService($this->accountId);
            $result = $service->analyzeElasticity($itemId);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao analisar elasticidade: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/analytics/competitive/:categoryId
     * Obtém análise competitiva da categoria
     */
    public function getCompetitiveAnalysis(string $categoryId): void
    {
        try {
            $service = new \App\Services\PriceAnalyticsService($this->accountId);
            $result = $service->getCompetitiveAnalysis($categoryId);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter análise competitiva: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/analytics/roi
     * Calcula ROI de mudança de preço
     */
    public function calculatePriceChangeROI(): void
    {
        try {
            $data = $this->request->json() ?? [];
            $service = new \App\Services\PriceAnalyticsService($this->accountId);
            $result = $service->calculatePriceChangeROI(
                $data['item_id'],
                $data['old_price'],
                $data['new_price'],
                $data['period_days'] ?? 30
            );
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao calcular ROI: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/analytics/forecast/:itemId
     * Previsão de preços futuros
     */
    public function forecastPrice(string $itemId): void
    {
        try {
            $days = $this->request->getInt('days', 30);
            $service = new \App\Services\PriceAnalyticsService($this->accountId);

            // Buscar histórico de preços do item para forecast
            $trend = $service->getPriceTrend($itemId, 90);
            $prices = [];
            if ($trend['success'] && !empty($trend['history'])) {
                foreach ($trend['history'] as $h) {
                    $prices[] = $h['price'] ?? $h['current_price'] ?? 0;
                }
            }

            $result = $service->forecastPrice($prices, $days);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao fazer previsão: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/analytics/report
     * Gera relatório de performance de preços
     */
    public function generateAnalyticsReport(): void
    {
        try {
            $period = $this->request->get('period', '30d');
            $service = new \App\Services\PriceAnalyticsService($this->accountId);
            $result = $service->generatePerformanceReport($period);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao gerar relatório: ' . $e->getMessage());
        }
    }

    // =====================================================
    // PHASE 3: BULK PRICE EDITOR
    // =====================================================

    /**
     * POST /api/pricing/:accountId/bulk/preview
     * Pré-visualiza alteração em massa
     *
     * Body: {
     *   "filters": { "category_id": "...", "item_ids": [...] },
     *   "operation": { "type": "percent_increase", "value": 10 }
     * }
     */
    public function previewBulkEdit(): void
    {
        try {
            $data = $this->request->json() ?? [];
            $filters = $data['filters'] ?? [];
            $operation = $data['operation'] ?? [];

            $service = new \App\Services\BulkPriceEditorService($this->accountId);
            $result = $service->preview($filters, $operation);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao prever edição em massa: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/bulk/apply
     * Aplica alteração em massa
     *
     * Body: {
     *   "filters": { "category_id": "...", "item_ids": [...] },
     *   "operation": { "type": "percent_increase", "value": 10 },
     *   "create_rollback": true
     * }
     */
    public function applyBulkEdit(): void
    {
        try {
            $data = $this->request->json() ?? [];
            $filters = $data['filters'] ?? [];
            $operation = $data['operation'] ?? [];
            $createRollback = $data['create_rollback'] ?? true;

            $service = new \App\Services\BulkPriceEditorService($this->accountId);
            $result = $service->apply($filters, $operation, $createRollback);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao aplicar edição em massa: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/bulk/:batchId/rollback
     * Reverte alteração em massa
     */
    public function rollbackBulkEdit(int $batchId): void
    {
        try {
            $service = new \App\Services\BulkPriceEditorService($this->accountId);
            $result = $service->rollback($batchId);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao reverter edição em massa: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/bulk/batches
     * Lista lotes de edição em massa
     */
    public function listBulkBatches(): void
    {
        try {
            $filters = [
                'status' => $this->request->get('status'),
                'limit' => $this->request->getInt('limit', 20),
                'offset' => $this->request->getInt('offset')
            ];
            $service = new \App\Services\BulkPriceEditorService($this->accountId);
            $result = $service->listBatches(array_filter($filters, fn($v) => $v !== null));
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao listar lotes: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/bulk/:batchId
     * Obtém detalhes de um lote
     */
    public function getBulkBatch(int $batchId): void
    {
        try {
            $service = new \App\Services\BulkPriceEditorService($this->accountId);
            $result = $service->getBatch($batchId);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter lote: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/bulk/templates
     * Obtém templates de operações em massa
     */
    public function getBulkOperationTemplates(): void
    {
        try {
            $service = new \App\Services\BulkPriceEditorService($this->accountId);
            $result = $service->getOperationTemplates();
            echo json_encode(['success' => true, 'templates' => $result]);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter templates: ' . $e->getMessage());
        }
    }

    // =====================================================
    // PHASE 3: PRICE NOTIFICATIONS
    // =====================================================

    /**
     * POST /api/pricing/:accountId/notifications/send
     * Envia notificação de evento de preço
     */
    public function sendNotification(): void
    {
        try {
            $data = $this->request->json() ?? [];
            $service = new \App\Services\PriceNotificationService($this->accountId);
            $result = $service->notify(
                $data['event'],
                $data['data'] ?? [],
                $data['severity'] ?? 'info'
            );
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao enviar notificação: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/notifications/channels
     * Cria um canal de notificação
     */
    public function createNotificationChannel(): void
    {
        try {
            $data = $this->request->json() ?? [];
            $service = new \App\Services\PriceNotificationService($this->accountId);
            $result = $service->createChannel($data);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao criar canal: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/notifications/channels
     * Lista canais de notificação
     */
    public function listNotificationChannels(): void
    {
        try {
            $service = new \App\Services\PriceNotificationService($this->accountId);
            $result = $service->listChannels();
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao listar canais: ' . $e->getMessage());
        }
    }

    /**
     * PUT /api/pricing/:accountId/notifications/channels/:channelId
     * Atualiza um canal de notificação
     */
    public function updateNotificationChannel(int $channelId): void
    {
        try {
            $data = $this->request->json() ?? [];
            $service = new \App\Services\PriceNotificationService($this->accountId);
            $result = $service->updateChannel($channelId, $data);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao atualizar canal: ' . $e->getMessage());
        }
    }

    /**
     * DELETE /api/pricing/:accountId/notifications/channels/:channelId
     * Exclui um canal de notificação
     */
    public function deleteNotificationChannel(int $channelId): void
    {
        try {
            $service = new \App\Services\PriceNotificationService($this->accountId);
            $result = $service->deleteChannel($channelId);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao excluir canal: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/notifications/channels/:channelId/test
     * Testa um canal de notificação
     */
    public function testNotificationChannel(int $channelId): void
    {
        try {
            $service = new \App\Services\PriceNotificationService($this->accountId);
            $result = $service->testChannel($channelId);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao testar canal: ' . $e->getMessage());
        }
    }

    /**
     * POST /api/pricing/:accountId/notifications/channels/:channelId/subscribe
     * Inscreve canal em um evento
     */
    public function subscribeToEvent(int $channelId): void
    {
        try {
            $data = $this->request->json() ?? [];
            $service = new \App\Services\PriceNotificationService($this->accountId);
            $result = $service->subscribe(
                $channelId,
                $data['event_type'],
                $data['min_severity'] ?? 1
            );
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao inscrever: ' . $e->getMessage());
        }
    }

    /**
     * DELETE /api/pricing/:accountId/notifications/channels/:channelId/subscribe/:event
     * Remove inscrição de evento
     */
    public function unsubscribeFromEvent(int $channelId, string $event): void
    {
        try {
            $service = new \App\Services\PriceNotificationService($this->accountId);
            $result = $service->unsubscribe($channelId, $event);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao remover inscrição: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/notifications/channels/:channelId/subscriptions
     * Lista inscrições de um canal
     */
    public function getChannelSubscriptions(int $channelId): void
    {
        try {
            $service = new \App\Services\PriceNotificationService($this->accountId);
            $result = $service->getSubscriptions($channelId);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao listar inscrições: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/notifications/history
     * Obtém histórico de notificações
     */
    public function getNotificationHistory(): void
    {
        try {
            $filters = [
                'event' => $this->request->get('event'),
                'channel_id' => $this->request->get('channel_id') !== null ? $this->request->getInt('channel_id') : null,
                'success' => $this->request->get('success') !== null ? $this->request->getBool('success') : null,
                'limit' => $this->request->getInt('limit', 50),
                'offset' => $this->request->getInt('offset')
            ];
            $service = new \App\Services\PriceNotificationService($this->accountId);
            $result = $service->getHistory(array_filter($filters, fn($v) => $v !== null));
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->error('Erro ao obter histórico: ' . $e->getMessage());
        }
    }

    /**
     * GET /api/pricing/:accountId/notifications/events
     * Lista eventos disponíveis para notificação
     */
    public function getNotificationEvents(): void
    {
        try {
            $service = new \App\Services\PriceNotificationService($this->accountId);
            echo json_encode([
                'success' => true,
                'events' => $service->getAvailableEvents(),
                'severities' => $service->getAvailableSeverities()
            ]);
        } catch (\Throwable $e) {
            $this->error('Erro ao listar eventos: ' . $e->getMessage());
        }
    }
}
