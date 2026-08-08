<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Services\Financial\HasFinancialDependencies;
use PDO;

/**
 * Servi\u00e7o respons\u00e1vel por relat\u00f3rios de settlements e releases do Mercado Pago.
 * Extra\u00eddo de FinancialService para melhor organiza\u00e7\u00e3o.
 */
class SettlementReportService
{
    use HasFinancialDependencies;

    // ============================================================================
    // SETTLEMENT REPORT (BILLING INTEGRATION)
    // ============================================================================

    /**
     * Obt\u00e9m relat\u00f3rio de liquida\u00e7\u00f5es (settlements) da API
     * Endpoint: GET /billing/integration/settlement_report
     *
     * @param string $startDate Data inicial (Y-m-d)
     * @param string $endDate Data final (Y-m-d)
     * @return array Relat\u00f3rio de liquida\u00e7\u00f5es
     */
    public function getSettlementReport(string $startDate, string $endDate): array
    {
        $sellerId = $this->getSellerId();
        if (!$sellerId) {
            return [
                'source' => 'none',
                'results' => [],
                'error' => 'Nenhuma conta Mercado Livre ativa — conecte uma conta para liquidações.',
                'period' => ['start' => $startDate, 'end' => $endDate],
                'note' => 'Nenhuma conta Mercado Livre ativa — conecte uma conta para liquidações.',
            ];
        }

        $client = $this->getClient();

        // Tentar endpoint de settlement report
        $params = [
            'user_id' => $sellerId,
            'date_from' => $startDate,
            'date_to' => $endDate,
        ];

        $response = $client->get('/billing/integration/settlement_report', $params);

        if (isset($response['error'])) {
            return $this->resolveSettlementFallback($startDate, $endDate);
        }

        $results = $response['results'] ?? null;
        if (!is_array($results) || $results === []) {
            $fallback = $this->resolveSettlementFallback($startDate, $endDate);
            if (($fallback['results'] ?? []) !== []) {
                return $fallback;
            }
        }

        return [
            'source' => 'api',
            'results' => is_array($results) ? $results : ($response ?? []),
            'period' => ['start' => $startDate, 'end' => $endDate],
        ];
    }

    /**
     * Local → estimado a partir de ml_orders (quando ainda não há settlements sincronizados).
     *
     * @return array<string, mixed>
     */
    private function resolveSettlementFallback(string $startDate, string $endDate): array
    {
        $local = $this->getLocalSettlements($startDate, $endDate);
        if (($local['results'] ?? []) !== []) {
            return $local;
        }

        return $this->getSettlementsFromOrders($startDate, $endDate);
    }

    /**
     * Estima liberações a partir das vendas pagas locais (líquido = bruto − comissão − taxa pagamento).
     *
     * @return array<string, mixed>
     */
    private function getSettlementsFromOrders(string $startDate, string $endDate): array
    {
        $where = [
            'date_created BETWEEN :start AND :end',
            "status IN ('paid', 'delivered', 'confirmed', 'ready_to_ship', 'shipped', 'handling')",
        ];
        $params = [
            ':start' => $startDate . (strlen($startDate) === 10 ? ' 00:00:00' : ''),
            ':end' => $endDate . (strlen($endDate) === 10 ? ' 23:59:59' : ''),
        ];
        if ($this->accountId) {
            $where[] = 'ml_account_id = :account_id';
            $params[':account_id'] = $this->accountId;
        }
        $whereSql = implode(' AND ', $where);

        try {
            $stmt = $this->db->prepare(
                "SELECT ml_order_id, date_created, total_amount, ml_commission, payment_fee, shipping_cost, status, order_data
                 FROM ml_orders
                 WHERE {$whereSql}
                 ORDER BY date_created DESC
                 LIMIT 500"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [
                'source' => 'orders_estimated',
                'results' => [],
                'total' => 0,
                'period' => ['start' => $startDate, 'end' => $endDate],
                'note' => 'Não foi possível estimar liquidações a partir de pedidos.',
            ];
        }

        $results = [];
        foreach ($rows as $row) {
            $orderData = [];
            if (!empty($row['order_data'])) {
                $decoded = is_string($row['order_data'])
                    ? json_decode($row['order_data'], true)
                    : $row['order_data'];
                $orderData = is_array($decoded) ? $decoded : [];
            }

            $gross = (float)($row['total_amount'] ?? 0);
            $mlFee = (float)($row['ml_commission'] ?? 0);
            if ($mlFee <= 0) {
                foreach ($orderData['order_items'] ?? [] as $item) {
                    $mlFee += (float)($item['sale_fee'] ?? 0);
                }
            }
            $paymentFee = (float)($row['payment_fee'] ?? 0);
            $fee = round($mlFee + $paymentFee, 2);
            $net = round($gross - $fee, 2);

            $results[] = [
                'date_released' => $row['date_created'] ?? null,
                'description' => 'Venda ML (estimativa de liberação)',
                'type' => 'sale',
                'order_id' => $row['ml_order_id'] ?? null,
                'external_reference' => $row['ml_order_id'] ?? null,
                'gross_amount' => round($gross, 2),
                'fee_amount' => $fee,
                'net_amount' => $net,
                'status' => 'ESTIMATED',
                'ml_record_id' => 'ORD-' . ($row['ml_order_id'] ?? ''),
            ];
        }

        return [
            'source' => 'orders_estimated',
            'results' => $results,
            'total' => count($results),
            'period' => ['start' => $startDate, 'end' => $endDate],
            'note' => 'Sem settlements sincronizados — estimativa a partir das vendas pagas (bruto − taxas ML/pagamento).',
        ];
    }

    /**
     * Obt\u00e9m settlements locais do banco de dados
     */
    private function getLocalSettlements(string $startDate, string $endDate): array
    {
        $where = ['date_released BETWEEN :start AND :end'];
        $params = [':start' => $startDate, ':end' => $endDate . ' 23:59:59'];

        if ($this->accountId) {
            $where[] = 'account_id = :account_id';
            $params[':account_id'] = $this->accountId;
        }

        $whereSql = implode(' AND ', $where);

        $sql = "SELECT * FROM financial_settlements WHERE {$whereSql} ORDER BY date_released DESC LIMIT 500";

        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt instanceof \PDOStatement) {
                return [
                    'source' => 'local',
                    'results' => [],
                    'error' => 'Tabela de settlements não disponível',
                ];
            }

            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'source' => 'local',
                'results' => $results,
                'total' => count($results),
                'period' => ['start' => $startDate, 'end' => $endDate],
            ];
        } catch (\Exception $e) {
            return [
                'source' => 'local',
                'results' => [],
                'error' => 'Tabela de settlements n\u00e3o dispon\u00edvel',
            ];
        }
    }

    // ============================================================================
    // MERCADO PAGO REPORTS - RELEASES (LIBERA\u00c7\u00d5ES)
    // API: /v1/account/release_report
    // ============================================================================

    /**
     * Cria relat\u00f3rio de libera\u00e7\u00f5es (dinheiro dispon\u00edvel)
     * API: POST /v1/account/release_report
     *
     * @param string $beginDate Data inicial (formato: Y-m-d\TH:i:s\Z)
     * @param string $endDate Data final
     * @return array Dados do relat\u00f3rio criado
     */
    public function createReleasesReport(string $beginDate, string $endDate): array
    {
        $payload = [
            'begin_date' => $beginDate,
            'end_date' => $endDate,
        ];

        $data = $this->mpPost('/v1/account/release_report', $payload);

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao criar relat\u00f3rio de libera\u00e7\u00f5es'];
        }

        return [
            'id' => $data['id'] ?? null,
            'account_id' => $data['account_id'] ?? null,
            'report_id' => $data['report_id'] ?? null,
            'status' => $data['status'] ?? 'pending',
            'begin_date' => $data['begin_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'created_from' => $data['created_from'] ?? 'manual',
            'currency_id' => $data['currency_id'] ?? 'BRL',
            'format' => $data['format'] ?? 'CSV',
            'generation_date' => $data['generation_date'] ?? null,
            'last_modified' => $data['last_modified'] ?? null,
            'is_test' => $data['is_test'] ?? false,
            'retries' => $data['retries'] ?? 0,
            'sub_type' => $data['sub_type'] ?? 'release',
        ];
    }

    /**
     * Consulta lista de relat\u00f3rios de libera\u00e7\u00f5es
     * API: GET /v1/account/release_report/list
     *
     * @param int $limit Limite de resultados
     * @param int $offset Offset para pagina\u00e7\u00e3o
     * @return array Lista de relat\u00f3rios
     */
    public function listReleasesReports(int $limit = 50, int $offset = 0): array
    {
        $data = $this->mpGet('/v1/account/release_report/list', [
            'limit' => $limit,
            'offset' => $offset,
        ]);

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao listar relat\u00f3rios'];
        }

        $rows = array_is_list($data) ? $data : [];

        return [
            'total' => count($rows),
            'reports' => array_map(function (array $report): array {
                return [
                    'id' => $report['id'] ?? null,
                    'report_id' => $report['report_id'] ?? null,
                    'file_name' => $report['file_name'] ?? null,
                    'status' => $report['status'] ?? null,
                    'begin_date' => $report['begin_date'] ?? null,
                    'end_date' => $report['end_date'] ?? null,
                    'created_from' => $report['created_from'] ?? null,
                    'generation_date' => $report['generation_date'] ?? null,
                    'download_url' => $report['download_url'] ?? null,
                ];
            }, $rows),
        ];
    }

    /**
     * Consulta status de tarefa de cria\u00e7\u00e3o de relat\u00f3rio
     * API: GET /v1/account/release_report/{report_id}
     *
     * @param int $reportId ID da tarefa de cria\u00e7\u00e3o
     * @return array Status do relat\u00f3rio
     */
    public function getReleasesReportStatus(int $reportId): array
    {
        $data = $this->mpGet('/v1/account/release_report/' . $reportId);

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao consultar relat\u00f3rio'];
        }

        return [
            'id' => $data['id'] ?? null,
            'report_id' => $data['report_id'] ?? null,
            'status' => $data['status'] ?? null,
            'file_name' => $data['file_name'] ?? null,
            'begin_date' => $data['begin_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'download_url' => $data['download_url'] ?? null,
            'generation_date' => $data['generation_date'] ?? null,
            'retries' => $data['retries'] ?? 0,
        ];
    }

    /**
     * Baixa relat\u00f3rio de libera\u00e7\u00f5es
     * API: GET /v1/account/release_report/{file_name}
     *
     * @param string $fileName Nome do arquivo do relat\u00f3rio
     * @return array|string Conte\u00fado do relat\u00f3rio ou erro
     */
    public function downloadReleasesReport(string $fileName): array|string
    {

        // O download retorna CSV raw, n\u00e3o JSON
        $data = $this->mpGet('/v1/account/release_report/' . rawurlencode($fileName));

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao baixar relat\u00f3rio'];
        }

        return $data;
    }

    /**
     * Obt\u00e9m configura\u00e7\u00f5es do relat\u00f3rio de libera\u00e7\u00f5es
     * API: GET /v1/account/release_report/config
     *
     * @return array Configura\u00e7\u00f5es
     */
    public function getReleasesReportConfig(): array
    {
        $data = $this->mpGet('/v1/account/release_report/config');

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao obter configura\u00e7\u00f5es'];
        }

        return [
            'file_name_prefix' => $data['file_name_prefix'] ?? null,
            'display_timezone' => $data['display_timezone'] ?? 'GMT-03',
            'scheduled' => $data['scheduled'] ?? false,
            'frequency' => $data['frequency'] ?? null,
            'sftp_info' => $data['sftp_info'] ?? null,
            'columns' => $data['columns'] ?? [],
            'include_withdraw' => $data['include_withdraw'] ?? true,
            'include_shipping' => $data['include_shipping'] ?? true,
        ];
    }

    /**
     * Cria/Atualiza configura\u00e7\u00f5es do relat\u00f3rio de libera\u00e7\u00f5es
     * API: POST/PUT /v1/account/release_report/config
     *
     * @param array $config Configura\u00e7\u00f5es
     * @param bool $update Se \u00e9 atualiza\u00e7\u00e3o (PUT) ou cria\u00e7\u00e3o (POST)
     * @return array Resultado
     */
    public function saveReleasesReportConfig(array $config, bool $update = false): array
    {

        $payload = [
            'display_timezone' => $config['display_timezone'] ?? 'GMT-03',
            'include_withdraw' => $config['include_withdraw'] ?? true,
            'include_shipping' => $config['include_shipping'] ?? true,
        ];

        if (!empty($config['file_name_prefix'])) {
            $payload['file_name_prefix'] = $config['file_name_prefix'];
        }

        if (!empty($config['columns'])) {
            $payload['columns'] = $config['columns'];
        }

        if ($update) {
            $data = $this->mpPut('/v1/account/release_report/config', $payload);
        } else {
            $data = $this->mpPost('/v1/account/release_report/config', $payload);
        }

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao salvar configura\u00e7\u00f5es'];
        }

        return [
            'success' => true,
            'config' => $data,
        ];
    }

    /**
     * Ativa gera\u00e7\u00e3o autom\u00e1tica de relat\u00f3rio de libera\u00e7\u00f5es
     * API: POST /v1/account/release_report/schedule
     *
     * @return array Resultado
     */
    public function enableReleasesAutoGeneration(): array
    {

        $data = $this->mpPost('/v1/account/release_report/schedule', []);

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao ativar gera\u00e7\u00e3o autom\u00e1tica'];
        }

        return [
            'success' => true,
            'scheduled' => true,
            'message' => 'Gera\u00e7\u00e3o autom\u00e1tica ativada',
        ];
    }

    /**
     * Desativa gera\u00e7\u00e3o autom\u00e1tica de relat\u00f3rio de libera\u00e7\u00f5es
     * API: DELETE /v1/account/release_report/schedule
     *
     * @return array Resultado
     */
    public function disableReleasesAutoGeneration(): array
    {

        $data = $this->mpDelete('/v1/account/release_report/schedule');

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao desativar gera\u00e7\u00e3o autom\u00e1tica'];
        }

        return [
            'success' => true,
            'scheduled' => false,
            'message' => 'Gera\u00e7\u00e3o autom\u00e1tica desativada',
        ];
    }

    // ============================================================================
    // MERCADO PAGO REPORTS - SETTLEMENTS (DINHEIRO EM CONTA)
    // API: /v1/account/settlement_report
    // ============================================================================

    /**
     * Cria relat\u00f3rio de dinheiro em conta (settlements)
     * API: POST /v1/account/settlement_report
     *
     * @param string $beginDate Data inicial (formato: Y-m-d\TH:i:s\Z)
     * @param string $endDate Data final
     * @return array Dados do relat\u00f3rio criado
     */
    public function createSettlementsReport(string $beginDate, string $endDate): array
    {

        $payload = [
            'begin_date' => $beginDate,
            'end_date' => $endDate,
        ];

        $data = $this->mpPost('/v1/account/settlement_report', $payload);

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao criar relat\u00f3rio de settlements'];
        }

        return [
            'id' => $data['id'] ?? null,
            'account_id' => $data['account_id'] ?? null,
            'report_id' => $data['report_id'] ?? null,
            'status' => $data['status'] ?? 'pending',
            'begin_date' => $data['begin_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'created_from' => $data['created_from'] ?? 'manual',
            'currency_id' => $data['currency_id'] ?? 'BRL',
            'format' => $data['format'] ?? 'CSV',
            'generation_date' => $data['generation_date'] ?? null,
            'last_modified' => $data['last_modified'] ?? null,
            'is_reserve' => $data['is_reserve'] ?? false,
            'is_test' => $data['is_test'] ?? false,
            'retries' => $data['retries'] ?? 0,
            'report_type' => $data['report_type'] ?? 'settlement',
        ];
    }

    /**
     * Consulta lista de relat\u00f3rios de settlements
     * API: GET /v1/account/settlement_report/list
     *
     * @param int $limit Limite de resultados
     * @param int $offset Offset para pagina\u00e7\u00e3o
     * @return array Lista de relat\u00f3rios
     */
    public function listSettlementsReports(int $limit = 50, int $offset = 0): array
    {
        $data = $this->mpGet('/v1/account/settlement_report/list', [
            'limit' => $limit,
            'offset' => $offset,
        ]);

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao listar relat\u00f3rios'];
        }

        $rows = array_is_list($data) ? $data : [];

        return [
            'total' => count($rows),
            'reports' => array_map(function (array $report): array {
                return [
                    'id' => $report['id'] ?? null,
                    'report_id' => $report['report_id'] ?? null,
                    'file_name' => $report['file_name'] ?? null,
                    'status' => $report['status'] ?? null,
                    'begin_date' => $report['begin_date'] ?? null,
                    'end_date' => $report['end_date'] ?? null,
                    'created_from' => $report['created_from'] ?? null,
                    'generation_date' => $report['generation_date'] ?? null,
                    'download_url' => $report['download_url'] ?? null,
                    'is_reserve' => $report['is_reserve'] ?? false,
                ];
            }, $rows),
        ];
    }


    /**
     * Consulta status de tarefa de cria\u00e7\u00e3o de settlement report
     * API: GET /v1/account/settlement_report/{report_id}
     *
     * @param int $reportId ID da tarefa de cria\u00e7\u00e3o
     * @return array Status do relat\u00f3rio
     */
    public function getSettlementsReportStatus(int $reportId): array
    {

        $data = $this->mpGet('/v1/account/settlement_report/' . $reportId);

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao consultar relat\u00f3rio'];
        }

        return [
            'id' => $data['id'] ?? null,
            'report_id' => $data['report_id'] ?? null,
            'status' => $data['status'] ?? null,
            'file_name' => $data['file_name'] ?? null,
            'begin_date' => $data['begin_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'download_url' => $data['download_url'] ?? null,
            'generation_date' => $data['generation_date'] ?? null,
            'is_reserve' => $data['is_reserve'] ?? false,
            'retries' => $data['retries'] ?? 0,
        ];
    }

    /**
     * Baixa relat\u00f3rio de settlements
     * API: GET /v1/account/settlement_report/{file_name}
     *
     * @param string $fileName Nome do arquivo do relat\u00f3rio
     * @return array|string Conte\u00fado do relat\u00f3rio ou erro
     */
    public function downloadSettlementsReport(string $fileName): array|string
    {

        $data = $this->mpGet('/v1/account/settlement_report/' . rawurlencode($fileName));

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao baixar relat\u00f3rio'];
        }

        return $data;
    }

    /**
     * Obt\u00e9m configura\u00e7\u00f5es do relat\u00f3rio de settlements
     * API: GET /v1/account/settlement_report/config
     *
     * @return array Configura\u00e7\u00f5es
     */
    public function getSettlementsReportConfig(): array
    {

        $data = $this->mpGet('/v1/account/settlement_report/config');

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao obter configura\u00e7\u00f5es'];
        }

        return [
            'file_name_prefix' => $data['file_name_prefix'] ?? null,
            'display_timezone' => $data['display_timezone'] ?? 'GMT-03',
            'scheduled' => $data['scheduled'] ?? false,
            'frequency' => $data['frequency'] ?? null,
            'sftp_info' => $data['sftp_info'] ?? null,
            'columns' => $data['columns'] ?? [],
            'separator' => $data['separator'] ?? ',',
        ];
    }

    /**
     * Cria/Atualiza configura\u00e7\u00f5es do relat\u00f3rio de settlements
     * API: POST/PUT /v1/account/settlement_report/config
     *
     * @param array $config Configura\u00e7\u00f5es
     * @param bool $update Se \u00e9 atualiza\u00e7\u00e3o (PUT) ou cria\u00e7\u00e3o (POST)
     * @return array Resultado
     */
    public function saveSettlementsReportConfig(array $config, bool $update = false): array
    {

        $payload = [
            'display_timezone' => $config['display_timezone'] ?? 'GMT-03',
            'separator' => $config['separator'] ?? ',',
        ];

        if (!empty($config['file_name_prefix'])) {
            $payload['file_name_prefix'] = $config['file_name_prefix'];
        }

        if (!empty($config['columns'])) {
            $payload['columns'] = $config['columns'];
        }

        if ($update) {
            $data = $this->mpPut('/v1/account/settlement_report/config', $payload);
        } else {
            $data = $this->mpPost('/v1/account/settlement_report/config', $payload);
        }

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao salvar configura\u00e7\u00f5es'];
        }

        return [
            'success' => true,
            'config' => $data,
        ];
    }

    /**
     * Ativa gera\u00e7\u00e3o autom\u00e1tica de relat\u00f3rio de settlements
     * API: POST /v1/account/settlement_report/schedule
     *
     * @return array Resultado
     */
    public function enableSettlementsAutoGeneration(): array
    {

        $data = $this->mpPost('/v1/account/settlement_report/schedule', []);

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao ativar gera\u00e7\u00e3o autom\u00e1tica'];
        }

        return [
            'success' => true,
            'scheduled' => true,
            'message' => 'Gera\u00e7\u00e3o autom\u00e1tica ativada',
        ];
    }

    /**
     * Desativa gera\u00e7\u00e3o autom\u00e1tica de relat\u00f3rio de settlements
     * API: DELETE /v1/account/settlement_report/schedule
     *
     * @return array Resultado
     */
    public function disableSettlementsAutoGeneration(): array
    {

        $data = $this->mpDelete('/v1/account/settlement_report/schedule');

        if (isset($data['error'])) {
            return ['error' => $data['message'] ?? 'Erro ao desativar gera\u00e7\u00e3o autom\u00e1tica'];
        }

        return [
            'success' => true,
            'scheduled' => false,
            'message' => 'Gera\u00e7\u00e3o autom\u00e1tica desativada',
        ];
    }

    // ============================================================================
    // CONSOLIDATED REPORTS & ANALYTICS
    // ============================================================================

    /**
     * Gera relat\u00f3rio consolidado de todos os tipos de relat\u00f3rios MP
     *
     * @param string $beginDate Data inicial
     * @param string $endDate Data final
     * @return array Status dos relat\u00f3rios
     */
    public function generateConsolidatedMPReports(string $beginDate, string $endDate): array
    {
        $releases = $this->createReleasesReport($beginDate, $endDate);
        $settlements = $this->createSettlementsReport($beginDate, $endDate);

        return [
            'period' => [
                'begin_date' => $beginDate,
                'end_date' => $endDate,
            ],
            'releases_report' => [
                'status' => isset($releases['error']) ? 'error' : 'created',
                'data' => $releases,
            ],
            'settlements_report' => [
                'status' => isset($settlements['error']) ? 'error' : 'created',
                'data' => $settlements,
            ],
            'instructions' => 'Aguarde alguns minutos para os relat\u00f3rios serem gerados. Consulte o status com getReleasesReportStatus() e getSettlementsReportStatus().',
        ];
    }

    /**
     * Verifica status de todos os relat\u00f3rios pendentes
     *
     * @return array Status consolidado
     */
    public function checkPendingReports(): array
    {
        $releases = $this->listReleasesReports(10, 0);
        $settlements = $this->listSettlementsReports(10, 0);

        $pendingReleases = [];
        $pendingSettlements = [];

        if (!isset($releases['error'])) {
            foreach ($releases['reports'] ?? [] as $report) {
                if ($report['status'] === 'pending') {
                    $pendingReleases[] = $report;
                }
            }
        }

        if (!isset($settlements['error'])) {
            foreach ($settlements['reports'] ?? [] as $report) {
                if ($report['status'] === 'pending') {
                    $pendingSettlements[] = $report;
                }
            }
        }

        return [
            'pending_releases' => [
                'count' => count($pendingReleases),
                'reports' => $pendingReleases,
            ],
            'pending_settlements' => [
                'count' => count($pendingSettlements),
                'reports' => $pendingSettlements,
            ],
            'total_pending' => count($pendingReleases) + count($pendingSettlements),
        ];
    }

    /**
     * Obt\u00e9m todos os relat\u00f3rios prontos para download
     *
     * @param int $limit Limite
     * @return array Relat\u00f3rios prontos
     */
    public function getReadyReports(int $limit = 20): array
    {
        $releases = $this->listReleasesReports($limit, 0);
        $settlements = $this->listSettlementsReports($limit, 0);

        $readyReleases = [];
        $readySettlements = [];

        if (!isset($releases['error'])) {
            foreach ($releases['reports'] ?? [] as $report) {
                if ($report['status'] === 'ready' && !empty($report['download_url'])) {
                    $readyReleases[] = $report;
                }
            }
        }

        if (!isset($settlements['error'])) {
            foreach ($settlements['reports'] ?? [] as $report) {
                if ($report['status'] === 'ready' && !empty($report['download_url'])) {
                    $readySettlements[] = $report;
                }
            }
        }

        return [
            'releases' => [
                'count' => count($readyReleases),
                'reports' => $readyReleases,
            ],
            'settlements' => [
                'count' => count($readySettlements),
                'reports' => $readySettlements,
            ],
            'total_ready' => count($readyReleases) + count($readySettlements),
        ];
    }
}
