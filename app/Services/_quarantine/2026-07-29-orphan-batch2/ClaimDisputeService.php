<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Services\Financial\HasFinancialDependencies;

class ClaimDisputeService
{
    use HasFinancialDependencies;

    /**
     * Busca reclamações (claims) do vendedor
     * Endpoint: GET /post-purchase/v1/claims/search
     *
     * @param string $status Status: opened, closed
     * @param string|null $stage Etapa: claim, dispute, recontact
     * @param int $limit Limite de resultados
     * @return array Lista de reclamações
     */
    public function getClaims(
        string $status = 'opened',
        ?string $stage = null,
        int $limit = 30
    ): array {
        $client = $this->getClient();

        $params = [
            'status' => $status,
            'limit' => min(100, $limit),
        ];

        if ($stage) {
            $params['stage'] = $stage;
        }

        try {
            $response = $client->get('/post-purchase/v1/claims/search', $params);
        } catch (\Exception $e) {
            log_error('Falha ao buscar reclamações', [
                'service' => 'ClaimDisputeService',
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
            return [
                'error' => $e->getMessage(),
                'results' => [],
            ];
        }

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar reclamações',
                'results' => [],
            ];
        }

        $claims = [];
        foreach ($response['data'] ?? [] as $claim) {
            $players = [];
            foreach ($claim['players'] ?? [] as $player) {
                $players[] = [
                    'role' => $player['role'] ?? null,
                    'type' => $player['type'] ?? null,
                    'user_id' => $player['user_id'] ?? null,
                    'has_actions' => !empty($player['available_actions']),
                ];
            }

            $claims[] = [
                'claim_id' => $claim['id'] ?? null,
                'resource_id' => $claim['resource_id'] ?? null,
                'status' => $claim['status'] ?? null,
                'type' => $claim['type'] ?? null,
                'stage' => $claim['stage'] ?? null,
                'resource' => $claim['resource'] ?? null,
                'reason_id' => $claim['reason_id'] ?? null,
                'fulfilled' => $claim['fulfilled'] ?? null,
                'quantity_type' => $claim['quantity_type'] ?? null,
                'players' => $players,
                'site_id' => $claim['site_id'] ?? null,
                'date_created' => $claim['date_created'] ?? null,
                'last_updated' => $claim['last_updated'] ?? null,
                'resolution' => $claim['resolution'] ?? null,
            ];
        }

        return [
            'results' => $claims,
            'paging' => $response['paging'] ?? ['total' => count($claims)],
            'status_filter' => $status,
        ];
    }

    /**
     * Obtém detalhes de uma reclamação específica
     * Endpoint: GET /post-purchase/v1/claims/{claim_id}
     *
     * @param string $claimId ID da reclamação
     * @return array Detalhes da reclamação
     */
    public function getClaimDetails(string $claimId): array
    {
        $client = $this->getClient();

        try {
            $response = $client->get("/post-purchase/v1/claims/{$claimId}");
        } catch (\Exception $e) {
            log_error('Falha ao buscar detalhes da reclamação', [
                'service' => 'ClaimDisputeService',
                'claim_id' => $claimId,
                'error' => $e->getMessage(),
            ]);
            return [
                'error' => $e->getMessage(),
                'data' => null,
            ];
        }

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Reclamação não encontrada',
                'data' => null,
            ];
        }

        $players = [];
        foreach ($response['players'] ?? [] as $player) {
            $actions = [];
            foreach ($player['available_actions'] ?? [] as $action) {
                $actions[] = [
                    'action' => $action['action'] ?? null,
                    'mandatory' => $action['mandatory'] ?? false,
                    'due_date' => $action['due_date'] ?? null,
                ];
            }

            $players[] = [
                'role' => $player['role'] ?? null,
                'type' => $player['type'] ?? null,
                'user_id' => $player['user_id'] ?? null,
                'available_actions' => $actions,
            ];
        }

        return [
            'claim_id' => $response['id'] ?? $claimId,
            'resource_id' => $response['resource_id'] ?? null,
            'status' => $response['status'] ?? null,
            'type' => $response['type'] ?? null,
            'stage' => $response['stage'] ?? null,
            'claim_version' => $response['claim_version'] ?? null,
            'claimed_quantity' => $response['claimed_quantity'] ?? null,
            'parent_id' => $response['parent_id'] ?? null,
            'resource' => $response['resource'] ?? null,
            'reason_id' => $response['reason_id'] ?? null,
            'fulfilled' => $response['fulfilled'] ?? null,
            'quantity_type' => $response['quantity_type'] ?? null,
            'players' => $players,
            'resolution' => $response['resolution'] ? [
                'reason' => $response['resolution']['reason'] ?? null,
                'date_created' => $response['resolution']['date_created'] ?? null,
                'benefited' => $response['resolution']['benefited'] ?? [],
                'closed_by' => $response['resolution']['closed_by'] ?? null,
                'applied_coverage' => $response['resolution']['applied_coverage'] ?? false,
            ] : null,
            'site_id' => $response['site_id'] ?? null,
            'date_created' => $response['date_created'] ?? null,
            'last_updated' => $response['last_updated'] ?? null,
            'related_entities' => $response['related_entities'] ?? [],
        ];
    }

    /**
     * Verifica se reclamação afeta a reputação do vendedor
     * Endpoint: GET /post-purchase/v1/claims/{claim_id}/affects-reputation
     *
     * @param string $claimId ID da reclamação
     * @return array Status de impacto na reputação
     */
    public function getClaimReputationImpact(string $claimId): array
    {
        $client = $this->getClient();

        try {
            $response = $client->get("/post-purchase/v1/claims/{$claimId}/affects-reputation");
        } catch (\Exception $e) {
            log_error('Falha ao verificar impacto de reclamação na reputação', [
                'service' => 'ClaimDisputeService',
                'claim_id' => $claimId,
                'error' => $e->getMessage(),
            ]);
            return [
                'error' => $e->getMessage(),
                'data' => null,
            ];
        }

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao verificar impacto',
                'data' => null,
            ];
        }

        return [
            'claim_id' => $claimId,
            'affects_reputation' => $response['affects_reputation'] ?? 'not_applies',
            'has_incentive' => $response['has_incentive'] ?? false,
            'due_date' => $response['due_date'] ?? null,
            'interpretation' => $this->interpretReputationImpact(
                $response['affects_reputation'] ?? 'not_applies'
            ),
        ];
    }

    /**
     * Interpreta o impacto na reputação
     */
    private function interpretReputationImpact(string $status): string
    {
        return match ($status) {
            'affected' => 'Esta reclamação AFETA sua reputação',
            'not_affected' => 'Esta reclamação NÃO afeta sua reputação',
            'not_applies' => 'Não aplicável (pagamento não vinculado a ordem)',
            default => 'Status desconhecido',
        };
    }

    /**
     * Obtém detalhes de uma devolução associada a uma reclamação
     * Endpoint: GET /post-purchase/v2/claims/{claim_id}/returns
     *
     * @param string $claimId ID da reclamação
     * @return array Detalhes da devolução
     */
    public function getReturnDetails(string $claimId): array
    {
        $client = $this->getClient();

        try {
            $response = $client->get("/post-purchase/v2/claims/{$claimId}/returns");
        } catch (\Exception $e) {
            log_error('Falha ao buscar detalhes de devolução', [
                'service' => 'ClaimDisputeService',
                'claim_id' => $claimId,
                'error' => $e->getMessage(),
            ]);
            return [
                'error' => $e->getMessage(),
                'data' => null,
            ];
        }

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Devolução não encontrada',
                'data' => null,
            ];
        }

        $shipments = [];
        foreach ($response['shipments'] ?? [] as $shipment) {
            $destination = $shipment['destination'] ?? [];
            $address = $destination['shipping_address'] ?? [];

            $shipments[] = [
                'shipment_id' => $shipment['shipment_id'] ?? null,
                'status' => $shipment['status'] ?? null,
                'tracking_number' => $shipment['tracking_number'] ?? null,
                'type' => $shipment['type'] ?? null,
                'destination' => [
                    'name' => $destination['name'] ?? null,
                    'city' => $address['city']['name'] ?? null,
                    'state' => $address['state']['name'] ?? null,
                    'zip_code' => $address['zip_code'] ?? null,
                ],
            ];
        }

        $orders = [];
        foreach ($response['orders'] ?? [] as $order) {
            $orders[] = [
                'order_id' => $order['order_id'] ?? null,
                'item_id' => $order['item_id'] ?? null,
                'variation_id' => $order['variation_id'] ?? null,
                'context_type' => $order['context_type'] ?? null,
                'total_quantity' => $order['total_quantity'] ?? null,
                'return_quantity' => $order['return_quantity'] ?? null,
            ];
        }

        return [
            'return_id' => $response['id'] ?? null,
            'claim_id' => $claimId,
            'status' => $response['status'] ?? null,
            'subtype' => $response['subtype'] ?? null,
            'refund_at' => $response['refund_at'] ?? null,
            'status_money' => $response['status_money'] ?? null,
            'resource_type' => $response['resource_type'] ?? null,
            'resource_id' => $response['resource_id'] ?? null,
            'shipments' => $shipments,
            'orders' => $orders,
            'intermediate_check' => $response['intermediate_check'] ?? false,
            'date_created' => $response['date_created'] ?? null,
            'date_closed' => $response['date_closed'] ?? null,
            'last_updated' => $response['last_updated'] ?? null,
            'related_entities' => $response['related_entities'] ?? [],
        ];
    }

    /**
     * Obtém custo de envio de devolução
     * Endpoint: GET /post-purchase/v1/claims/{claim_id}/charges/return-cost
     *
     * @param string $claimId ID da reclamação
     * @param bool $calculateUsd Se deve calcular em USD
     * @return array Custo da devolução
     */
    public function getReturnShippingCost(string $claimId, bool $calculateUsd = false): array
    {
        $client = $this->getClient();

        $params = [];
        if ($calculateUsd) {
            $params['calculate_amount_usd'] = 'true';
        }

        try {
            $response = $client->get(
                "/post-purchase/v1/claims/{$claimId}/charges/return-cost",
                $params
            );
        } catch (\Exception $e) {
            log_error('Falha ao buscar custo de devolução', [
                'service' => 'ClaimDisputeService',
                'claim_id' => $claimId,
                'error' => $e->getMessage(),
            ]);
            return [
                'error' => $e->getMessage(),
                'data' => null,
            ];
        }

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Erro ao buscar custo de devolução',
                'data' => null,
            ];
        }

        return [
            'claim_id' => $claimId,
            'currency_id' => $response['currency_id'] ?? 'BRL',
            'amount' => (float)($response['amount'] ?? 0),
            'amount_usd' => isset($response['amount_usd']) ? (float)$response['amount_usd'] : null,
        ];
    }

    /**
     * Gera relatório consolidado de reclamações e devoluções com impacto financeiro
     *
     * @param string $startDate Data inicial
     * @param string $endDate Data final
     * @return array Relatório de reclamações
     */
    public function getClaimsFinancialReport(string $startDate, string $endDate): array
    {
        // Buscar claims abertas e fechadas
        $openedClaims = $this->getClaims('opened', null, 100);
        $closedClaims = $this->getClaims('closed', null, 100);

        $allClaims = array_merge(
            $openedClaims['results'] ?? [],
            $closedClaims['results'] ?? []
        );

        // Filtrar por período
        $filteredClaims = array_filter($allClaims, function (array $claim) use ($startDate, $endDate): bool {
            $claimDate = $claim['date_created'] ?? null;
            if (!$claimDate) {
                return false;
            }
            $date = strtotime($claimDate);
            return $date >= strtotime($startDate) && $date <= strtotime($endDate . ' 23:59:59');
        });

        // Estatísticas
        $stats = [
            'total' => count($filteredClaims),
            'opened' => 0,
            'closed' => 0,
            'by_type' => [],
            'by_stage' => [],
            'by_reason' => [],
            'affecting_reputation' => 0,
        ];

        $claimsWithDetails = [];
        foreach ($filteredClaims as $claim) {
            $status = $claim['status'] ?? 'unknown';
            $type = $claim['type'] ?? 'unknown';
            $stage = $claim['stage'] ?? 'unknown';
            $reason = $claim['reason_id'] ?? 'unknown';

            $stats[$status] = ($stats[$status] ?? 0) + 1;
            $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
            $stats['by_stage'][$stage] = ($stats['by_stage'][$stage] ?? 0) + 1;
            $stats['by_reason'][$reason] = ($stats['by_reason'][$reason] ?? 0) + 1;

            // Verificar impacto na reputação (limitado para não sobrecarregar API)
            if (count($claimsWithDetails) < 10 && $status === 'opened') {
                $claimId = $claim['claim_id'] ?? null;
                if ($claimId) {
                    $impact = $this->getClaimReputationImpact($claimId);
                    if (($impact['affects_reputation'] ?? '') === 'affected') {
                        $stats['affecting_reputation']++;
                    }
                    $claim['reputation_impact'] = $impact;
                }
            }

            $claimsWithDetails[] = $claim;
        }

        return [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'statistics' => $stats,
            'claims' => array_slice($claimsWithDetails, 0, 50),
            'summary' => [
                'resolution_rate' => $stats['total'] > 0
                    ? round(($stats['closed'] / $stats['total']) * 100, 2)
                    : 0,
                'most_common_type' => !empty($stats['by_type'])
                    ? array_keys($stats['by_type'], max($stats['by_type']))[0]
                    : null,
                'reputation_risk_count' => $stats['affecting_reputation'],
            ],
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Obtém histórico de ações de uma reclamação
     * Endpoint: GET /post-purchase/v1/claims/{claim_id}/actions-history
     *
     * @param string $claimId ID da reclamação
     * @return array Histórico de ações
     */
    public function getClaimActionsHistory(string $claimId): array
    {
        $client = $this->getClient();

        try {
            $response = $client->get("/post-purchase/v1/claims/{$claimId}/actions-history");
        } catch (\Exception $e) {
            log_error('Falha ao buscar histórico de ações da reclamação', [
                'service' => 'ClaimDisputeService',
                'claim_id' => $claimId,
                'error' => $e->getMessage(),
            ]);
            return [
                'error' => $e->getMessage(),
                'results' => [],
            ];
        }

        if (isset($response['error'])) {
            return [
                'error' => $response['message'] ?? 'Histórico não encontrado',
                'results' => [],
            ];
        }

        $actions = [];
        foreach ($response as $action) {
            $actions[] = [
                'action_name' => $action['action_name'] ?? null,
                'player_role' => $action['player_role'] ?? null,
                'action_reason_id' => $action['action_reason_id'] ?? null,
                'claim_stage' => $action['claim_stage'] ?? null,
                'claim_status' => $action['claim_status'] ?? null,
                'date_created' => $action['date_created'] ?? null,
            ];
        }

        return [
            'claim_id' => $claimId,
            'actions' => $actions,
            'total' => count($actions),
        ];
    }

    /**
     * Busca reclamações no MP
     * GET /post-purchase/v1/claims/search
     *
     * @param array $filters Filtros
     * @return array Lista de reclamações
     */
    public function searchClaims(array $filters = []): array
    {
        $client = $this->getMercadoPagoClient();

        $params = [];
        // MP API requires at least one filter; default to 'opened' status
        $params['status'] = !empty($filters['status']) ? $filters['status'] : 'opened';
        if (!empty($filters['resource_id'])) {
            $params['resource_id'] = $filters['resource_id']; // payment_id ou order_id
        }
        if (!empty($filters['date_from'])) {
            $params['date_created_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $params['date_created_to'] = $filters['date_to'];
        }
        $params['offset'] = $filters['offset'] ?? 0;
        $params['limit'] = $filters['limit'] ?? 50;

        $queryString = http_build_query($params);
        return $client->get("https://api.mercadopago.com/post-purchase/v1/claims/search?{$queryString}");
    }

    /**
     * Obtém detalhes de uma reclamação
     * GET /post-purchase/v1/claims/{claim_id}
     *
     * @param string $claimId ID da reclamação
     * @return array Detalhes da reclamação
     */
    public function getClaim(string $claimId): array
    {
        $client = $this->getMercadoPagoClient();
        return $client->get("https://api.mercadopago.com/post-purchase/v1/claims/{$claimId}");
    }

    /**
     * Obtém motivos da reclamação
     * GET /post-purchase/v1/claims/{claim_id}/reason
     *
     * @param string $claimId ID da reclamação
     * @return array Motivos da reclamação
     */
    public function getClaimReason(string $claimId): array
    {
        $client = $this->getMercadoPagoClient();
        return $client->get("https://api.mercadopago.com/post-purchase/v1/claims/{$claimId}/reason");
    }

    /**
     * Obtém histórico da reclamação
     * GET /post-purchase/v1/claims/{claim_id}/history
     *
     * @param string $claimId ID da reclamação
     * @return array Histórico da reclamação
     */
    public function getClaimHistory(string $claimId): array
    {
        $client = $this->getMercadoPagoClient();
        return $client->get("https://api.mercadopago.com/post-purchase/v1/claims/{$claimId}/history");
    }

    /**
     * Obtém evidências da reclamação
     * GET /post-purchase/v1/claims/{claim_id}/evidence
     *
     * @param string $claimId ID da reclamação
     * @return array Evidências da reclamação
     */
    public function getClaimEvidence(string $claimId): array
    {
        $client = $this->getMercadoPagoClient();
        return $client->get("https://api.mercadopago.com/post-purchase/v1/claims/{$claimId}/evidence");
    }

    /**
     * Obtém notificações da reclamação
     * GET /post-purchase/v1/claims/{claim_id}/notifications
     *
     * @param string $claimId ID da reclamação
     * @return array Notificações da reclamação
     */
    public function getClaimNotifications(string $claimId): array
    {
        $client = $this->getMercadoPagoClient();
        return $client->get("https://api.mercadopago.com/post-purchase/v1/claims/{$claimId}/notifications");
    }

    /**
     * Obtém mensagens da reclamação
     * GET /post-purchase/v1/claims/{claim_id}/messages
     *
     * @param string $claimId ID da reclamação
     * @return array Mensagens da reclamação
     */
    public function getClaimMessages(string $claimId): array
    {
        $client = $this->getMercadoPagoClient();
        return $client->get("https://api.mercadopago.com/post-purchase/v1/claims/{$claimId}/messages");
    }

    /**
     * Envia mensagem em uma reclamação
     * POST /post-purchase/v1/claims/{claim_id}/messages
     *
     * @param string $claimId ID da reclamação
     * @param string $message Mensagem a enviar
     * @param array $attachments IDs dos arquivos anexados
     * @return array Resultado do envio
     */
    public function sendClaimMessage(string $claimId, string $message, array $attachments = []): array
    {
        $client = $this->getMercadoPagoClient();

        $payload = [
            'message' => $message,
        ];

        if (!empty($attachments)) {
            $payload['attachment_ids'] = $attachments;
        }

        return $client->post("https://api.mercadopago.com/post-purchase/v1/claims/{$claimId}/messages", $payload);
    }

    /**
     * Anexa arquivo a uma mensagem de reclamação
     * POST /post-purchase/v1/claims/{claim_id}/attachments
     *
     * @param string $claimId ID da reclamação
     * @param string $filePath Caminho do arquivo
     * @param string $fileName Nome do arquivo
     * @return array Resultado do upload
     */
    public function attachClaimFile(string $claimId, string $filePath, string $fileName): array
    {
        $client = $this->getMercadoPagoClient();

        // Envia como multipart/form-data
        $fileContent = base64_encode(file_get_contents($filePath));

        return $client->post("https://api.mercadopago.com/post-purchase/v1/claims/{$claimId}/attachments", [
            'file' => $fileContent,
            'file_name' => $fileName,
        ]);
    }

    /**
     * Solicita mediação para uma reclamação
     * POST /post-purchase/v1/claims/{claim_id}/mediation
     *
     * @param string $claimId ID da reclamação
     * @return array Resultado da solicitação
     */
    public function requestClaimMediation(string $claimId): array
    {
        $client = $this->getMercadoPagoClient();
        return $client->post("https://api.mercadopago.com/post-purchase/v1/claims/{$claimId}/mediation");
    }

    /**
     * Visualiza resoluções esperadas na mediação
     * GET /post-purchase/v1/claims/{claim_id}/resolutions
     *
     * @param string $claimId ID da reclamação
     * @return array Resoluções esperadas
     */
    public function getExpectedResolutions(string $claimId): array
    {
        $client = $this->getMercadoPagoClient();
        return $client->get("https://api.mercadopago.com/post-purchase/v1/claims/{$claimId}/resolutions");
    }

    /**
     * Carrega evidência de envio
     * POST /post-purchase/v1/claims/{claim_id}/shipping-evidence
     *
     * @param string $claimId ID da reclamação
     * @param array $shippingData Dados do envio
     * @return array Resultado
     */
    public function uploadShippingEvidence(string $claimId, array $shippingData): array
    {
        $client = $this->getMercadoPagoClient();
        return $client->post(
            "https://api.mercadopago.com/post-purchase/v1/claims/{$claimId}/shipping-evidence",
            $shippingData
        );
    }

    /**
     * Obtém análise de reclamações
     *
     * @param string $startDate Data inicial (Y-m-d)
     * @param string $endDate Data final (Y-m-d)
     * @return array Análise de reclamações
     */
    public function analyzeClaimsPerformance(string $startDate, string $endDate): array
    {
        $claims = $this->searchClaims([
            'date_from' => $startDate . 'T00:00:00.000-03:00',
            'date_to' => $endDate . 'T23:59:59.999-03:00',
            'limit' => 100,
        ]);

        $totalClaims = count($claims['results'] ?? []);
        $byStatus = [];
        $byReason = [];
        $resolved = 0;
        $pending = 0;
        $avgResolutionTime = 0;
        $resolutionTimes = [];

        foreach ($claims['results'] ?? [] as $claim) {
            // Por status
            $status = $claim['status'] ?? 'unknown';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

            // Por motivo
            $reason = $claim['reason'] ?? $claim['type'] ?? 'unknown';
            $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;

            // Contagem de resolvidas
            if (in_array($status, ['closed', 'resolved', 'finalized'])) {
                $resolved++;
                // Tempo de resolução
                if (!empty($claim['date_created']) && !empty($claim['last_modified'])) {
                    $created = strtotime($claim['date_created']);
                    $modified = strtotime($claim['last_modified']);
                    $resolutionTimes[] = ($modified - $created) / 86400; // Dias
                }
            } else {
                $pending++;
            }
        }

        if (!empty($resolutionTimes)) {
            $avgResolutionTime = array_sum($resolutionTimes) / count($resolutionTimes);
        }

        $resolutionRate = $totalClaims > 0 ? ($resolved / $totalClaims) * 100 : 0;

        return [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'total_claims' => $totalClaims,
            'resolved' => $resolved,
            'pending' => $pending,
            'resolution_rate' => round($resolutionRate, 2),
            'avg_resolution_days' => round($avgResolutionTime, 1),
            'by_status' => $byStatus,
            'by_reason' => $byReason,
            'health_indicator' => $this->calculateClaimsHealthIndicator($totalClaims, $resolutionRate, $avgResolutionTime),
        ];
    }

    /**
     * Calcula indicador de saúde de reclamações
     */
    private function calculateClaimsHealthIndicator(int $totalClaims, float $resolutionRate, float $avgDays): array
    {
        $score = 100;

        // Penalidade por volume (mais de 10 reclamações no período é preocupante)
        if ($totalClaims > 10) {
            $score -= min(30, ($totalClaims - 10) * 2);
        }

        // Penalidade por taxa de resolução baixa
        if ($resolutionRate < 80) {
            $score -= (80 - $resolutionRate);
        }

        // Penalidade por tempo de resolução longo (ideal < 3 dias)
        if ($avgDays > 3) {
            $score -= min(20, ($avgDays - 3) * 3);
        }

        $score = max(0, min(100, $score));

        return [
            'score' => round($score),
            'status' => $score >= 80 ? 'excellent' : ($score >= 60 ? 'good' : ($score >= 40 ? 'attention' : 'critical')),
            'recommendations' => $this->getClaimsRecommendations($totalClaims, $resolutionRate, $avgDays),
        ];
    }

    /**
     * Obtém recomendações baseadas na análise de reclamações
     */
    private function getClaimsRecommendations(int $totalClaims, float $resolutionRate, float $avgDays): array
    {
        $recommendations = [];

        if ($totalClaims > 10) {
            $recommendations[] = 'Alto volume de reclamações. Revise a qualidade dos produtos/serviços.';
        }
        if ($resolutionRate < 80) {
            $recommendations[] = 'Taxa de resolução abaixo do ideal. Priorize a resolução de reclamações pendentes.';
        }
        if ($avgDays > 5) {
            $recommendations[] = 'Tempo médio de resolução muito alto. Implemente processos mais ágeis.';
        }
        if ($avgDays > 3 && $avgDays <= 5) {
            $recommendations[] = 'Considere automatizar respostas iniciais para agilizar atendimento.';
        }

        if (empty($recommendations)) {
            $recommendations[] = 'Bom desempenho no gerenciamento de reclamações. Continue monitorando.';
        }

        return $recommendations;
    }
}
