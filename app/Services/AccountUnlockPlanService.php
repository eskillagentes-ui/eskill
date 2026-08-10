<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;
use Throwable;

/**
 * Plano de Destravamento (TRAVADA → VERDE) — somente leitura / plano manual.
 *
 * Une governança (TÓXICO/POLUIDOR), bloqueios de catálogo (ml_sales_blockers)
 * e detalhes de moderação via API ML (read-only). Nunca escreve no ML.
 */
final class AccountUnlockPlanService
{
    private const PROBLEM_CLASSES = [
        AccountGovernanceService::CLASS_TOXICO,
        AccountGovernanceService::CLASS_POLUIDOR,
        AccountGovernanceService::CLASS_MORTO,
        AccountGovernanceService::CLASS_SEM_ESTOQUE,
        AccountGovernanceService::CLASS_FRACO,
        AccountGovernanceService::CLASS_EM_RISCO,
    ];

    private const FLAG_LABELS = [
        'HIGH_TRAFFIC' => 'alto tráfego relativo',
        'MED_TRAFFIC' => 'tráfego médio',
        'LOW_TRAFFIC' => 'tráfego baixo',
        'VERY_BAD_CONV' => 'conversão muito baixa (≥100 visitas)',
        'BAD_CONV' => 'conversão baixa (≥50 visitas)',
        'NO_SALES_30' => 'sem vendas em 30 dias',
        'NO_SALES_14' => 'sem vendas em 14 dias',
        'OOS' => 'sem estoque',
        'LOW_STOCK' => 'estoque baixo',
        'STALE' => 'anúncio estagnado (poucas visitas + sem vendas)',
        'FALLING' => 'tendência de visitas em queda',
    ];

    private PDO $db;
    private ?int $accountId;
    private ?MercadoLivreClient $client;

    public function __construct(?int $accountId = null, ?PDO $db = null, ?MercadoLivreClient $client = null)
    {
        $this->accountId = $accountId;
        $this->db = $db ?? Database::getInstance();
        $this->client = $client;
        $this->ensureSchema();
    }

    /**
     * @param array<string, mixed> $govResult Resultado de AccountGovernanceService::runFullDiagnostic()
     * @return array{
     *   generated_at: string,
     *   account_status: string,
     *   items: list<array<string, mixed>>,
     *   summary: array{total:int,pending:int,resolved:int,critical:int}
     * }
     */
    public function buildAndPersist(array $govResult, ?string $reportId = null): array
    {
        if ($this->accountId === null || $this->accountId <= 0) {
            throw new \InvalidArgumentException('account_id obrigatório');
        }

        $items = $this->buildItems($govResult);
        $this->persistPlan($items, $reportId);

        $pending = 0;
        $resolved = 0;
        $critical = 0;
        foreach ($items as $item) {
            if (($item['status'] ?? 'pending') === 'resolved') {
                $resolved++;
            } else {
                $pending++;
            }
            if (($item['priority'] ?? '') === 'CRITICA') {
                $critical++;
            }
        }

        return [
            'generated_at' => date('c'),
            'account_status' => (string) ($govResult['account_status'] ?? 'UNKNOWN'),
            'items' => $items,
            'summary' => [
                'total' => count($items),
                'pending' => $pending,
                'resolved' => $resolved,
                'critical' => $critical,
            ],
            'manual_only' => true,
            'ml_write' => false,
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function loadLatest(int $accountId): array
    {
        $stmt = $this->db->prepare(
            'SELECT mlb_id, title, classification, reason, recommended_action, impact_score,
                    priority, status, moderation_detail, catalog_blocker, source, updated_at
             FROM account_unlock_plan_items
             WHERE account_id = ?
             ORDER BY
               CASE status WHEN \'pending\' THEN 0 ELSE 1 END,
               impact_score DESC,
               id DESC'
        );
        $stmt->execute([$accountId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($items as &$item) {
            $item['impact_score'] = (int) ($item['impact_score'] ?? 0);
            $item['moderation_detail'] = $this->decodeJson($item['moderation_detail'] ?? null);
            $item['catalog_blocker'] = $this->decodeJson($item['catalog_blocker'] ?? null);
        }
        unset($item);

        $pending = count(array_filter($items, static fn(array $i): bool => ($i['status'] ?? '') === 'pending'));
        $resolved = count($items) - $pending;
        $critical = count(array_filter($items, static fn(array $i): bool => ($i['priority'] ?? '') === 'CRITICA' && ($i['status'] ?? '') === 'pending'));

        return [
            'items' => $items,
            'summary' => [
                'total' => count($items),
                'pending' => $pending,
                'resolved' => $resolved,
                'critical' => $critical,
            ],
        ];
    }

    public function markResolved(int $accountId, string $mlbId): bool
    {
        $mlbId = strtoupper(trim($mlbId));
        $stmt = $this->db->prepare(
            'UPDATE account_unlock_plan_items
             SET status = \'resolved\', resolved_at = NOW(), updated_at = NOW()
             WHERE account_id = ? AND mlb_id = ? AND status = \'pending\''
        );
        $stmt->execute([$accountId, $mlbId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reconcilia itens resolvidos quando o próximo Raio X confirma que saíram da classe problemática.
     *
     * @param array<string, mixed> $govResult
     */
    public function reconcileResolvedFromGovernance(array $govResult): int
    {
        if ($this->accountId === null) {
            return 0;
        }

        $problemIds = [];
        foreach ($govResult['items'] ?? [] as $item) {
            $class = (string) ($item['classification'] ?? '');
            if (in_array($class, self::PROBLEM_CLASSES, true)) {
                $id = strtoupper(trim((string) ($item['id'] ?? $item['ml_item_id'] ?? '')));
                if ($id !== '') {
                    $problemIds[$id] = true;
                }
            }
        }

        $stmt = $this->db->prepare(
            'SELECT mlb_id FROM account_unlock_plan_items
             WHERE account_id = ? AND status = \'pending\''
        );
        $stmt->execute([$this->accountId]);
        $pending = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $resolved = 0;
        $upd = $this->db->prepare(
            'UPDATE account_unlock_plan_items
             SET status = \'resolved\', resolved_at = NOW(), updated_at = NOW(),
                 note = COALESCE(note, \'auto-resolvido no Raio X seguinte\')
             WHERE account_id = ? AND mlb_id = ? AND status = \'pending\''
        );
        foreach ($pending as $mlb) {
            $mlb = strtoupper((string) $mlb);
            if ($mlb === '' || isset($problemIds[$mlb])) {
                continue;
            }
            // Só auto-resolve classes de governança — moderação/catálogo exigem confirmação humana
            $src = $this->db->prepare(
                'SELECT source FROM account_unlock_plan_items WHERE account_id = ? AND mlb_id = ? LIMIT 1'
            );
            $src->execute([$this->accountId, $mlb]);
            $source = (string) $src->fetchColumn();
            if ($source !== 'governance') {
                continue;
            }
            $upd->execute([$this->accountId, $mlb]);
            $resolved += $upd->rowCount();
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $govResult
     * @return list<array<string, mixed>>
     */
    private function buildItems(array $govResult): array
    {
        /** @var array<string, array<string, mixed>> $byMlb */
        $byMlb = [];

        foreach ($govResult['items'] ?? [] as $item) {
            $class = (string) ($item['classification'] ?? '');
            if (!in_array($class, self::PROBLEM_CLASSES, true)) {
                continue;
            }
            $mlb = strtoupper(trim((string) ($item['id'] ?? $item['ml_item_id'] ?? '')));
            if ($mlb === '') {
                continue;
            }
            $flags = is_array($item['flags'] ?? null) ? $item['flags'] : [];
            $reason = $this->reasonFromFlags($class, $flags, $item);
            $action = $this->primaryAction($item);
            $impact = $this->impactForClass($class, $flags);
            $priority = $this->priorityForClass($class);

            $byMlb[$mlb] = [
                'mlb_id' => $mlb,
                'title' => (string) ($item['title'] ?? $mlb),
                'classification' => $class,
                'reason' => $reason,
                'recommended_action' => $action,
                'impact_score' => $impact,
                'priority' => $priority,
                'status' => 'pending',
                'moderation_detail' => null,
                'catalog_blocker' => null,
                'source' => 'governance',
                'manual_execution' => true,
            ];
        }

        foreach ($this->loadCatalogBlockers() as $blocker) {
            $mlb = strtoupper((string) $blocker['item_id']);
            $detail = [
                'reason' => (string) ($blocker['reason'] ?? ''),
                'remedy' => (string) ($blocker['remedy'] ?? ''),
                'playbook' => $blocker['playbook'] ?? null,
                'seller_edit_url' => $blocker['seller_edit_url'] ?? null,
            ];
            if (isset($byMlb[$mlb])) {
                $byMlb[$mlb]['catalog_blocker'] = $detail;
                $byMlb[$mlb]['reason'] .= ' · Bloqueio de catálogo: ' . ($detail['reason'] ?: 'waiting_for_patch');
                $byMlb[$mlb]['recommended_action'] = 'Vincular/aceitar produto de catálogo sugerido no painel ML (OPT_OBEY) e republicar';
                $byMlb[$mlb]['impact_score'] = max((int) $byMlb[$mlb]['impact_score'], 40);
                $byMlb[$mlb]['priority'] = 'CRITICA';
                $byMlb[$mlb]['source'] = 'governance+catalog';
            } else {
                $byMlb[$mlb] = [
                    'mlb_id' => $mlb,
                    'title' => (string) ($blocker['title'] ?? $mlb),
                    'classification' => 'CATALOG_BLOCK',
                    'reason' => 'Bloqueio de catálogo: ' . ($detail['reason'] ?: 'anúncio em under_review / waiting_for_patch'),
                    'recommended_action' => $detail['remedy'] !== ''
                        ? $detail['remedy']
                        : 'Aceitar produto de catálogo sugerido no painel ML e republicar',
                    'impact_score' => 40,
                    'priority' => 'CRITICA',
                    'status' => 'pending',
                    'moderation_detail' => null,
                    'catalog_blocker' => $detail,
                    'source' => 'catalog',
                    'manual_execution' => true,
                ];
            }
        }

        // Moderação específica citada (FACILYTY)
        $moderationMlb = 'MLB7346643828';
        $modDetail = $this->fetchModerationDetail($moderationMlb);
        if ($modDetail !== null) {
            if (isset($byMlb[$moderationMlb])) {
                $byMlb[$moderationMlb]['moderation_detail'] = $modDetail;
                $byMlb[$moderationMlb]['reason'] = $modDetail['reason_display'];
                $byMlb[$moderationMlb]['recommended_action'] = $modDetail['recommended_action'];
                $byMlb[$moderationMlb]['impact_score'] = max((int) $byMlb[$moderationMlb]['impact_score'], 55);
                $byMlb[$moderationMlb]['priority'] = 'CRITICA';
                $byMlb[$moderationMlb]['source'] = ($byMlb[$moderationMlb]['source'] ?? 'governance') . '+moderation';
            } else {
                $byMlb[$moderationMlb] = [
                    'mlb_id' => $moderationMlb,
                    'title' => $modDetail['title'],
                    'classification' => 'MODERATION',
                    'reason' => $modDetail['reason_display'],
                    'recommended_action' => $modDetail['recommended_action'],
                    'impact_score' => 55,
                    'priority' => 'CRITICA',
                    'status' => 'pending',
                    'moderation_detail' => $modDetail,
                    'catalog_blocker' => null,
                    'source' => 'moderation',
                    'manual_execution' => true,
                ];
            }
        }

        // Preservar status resolved já marcado pelo usuário
        $resolvedMap = $this->loadResolvedMap();
        foreach ($byMlb as $mlb => &$row) {
            if (isset($resolvedMap[$mlb])) {
                $row['status'] = 'resolved';
            }
        }
        unset($row);

        $list = array_values($byMlb);
        usort($list, static function (array $a, array $b): int {
            $sa = ($a['status'] ?? '') === 'pending' ? 0 : 1;
            $sb = ($b['status'] ?? '') === 'pending' ? 0 : 1;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            return ((int) $b['impact_score']) <=> ((int) $a['impact_score']);
        });

        return $list;
    }

    /**
     * @param array<string, bool> $flags
     * @param array<string, mixed> $item
     */
    private function reasonFromFlags(string $class, array $flags, array $item): string
    {
        $active = [];
        foreach ($flags as $key => $on) {
            if ($on && isset(self::FLAG_LABELS[$key])) {
                $active[] = self::FLAG_LABELS[$key];
            }
        }
        $visits = (int) ($item['visits_30d'] ?? 0);
        $sales = (int) ($item['sales_30d'] ?? 0);
        $conv = $visits > 0 ? round(($sales / $visits) * 100, 2) : 0.0;

        $prefix = match ($class) {
            AccountGovernanceService::CLASS_TOXICO => 'TÓXICO: alto tráfego com conversão muito ruim',
            AccountGovernanceService::CLASS_POLUIDOR => 'POLUIDOR: tráfego médio com conversão ruim',
            AccountGovernanceService::CLASS_MORTO => 'MORTO: sem tráfego e sem vendas (polui catálogo)',
            AccountGovernanceService::CLASS_SEM_ESTOQUE => 'SEM ESTOQUE: anúncio ativo sem quantidade',
            AccountGovernanceService::CLASS_FRACO => 'FRACO: sem vendas recentes',
            AccountGovernanceService::CLASS_EM_RISCO => 'EM RISCO: tendência negativa',
            default => $class,
        };

        $metrics = sprintf(' (visitas 30d=%d, vendas 30d=%d, conv=%.2f%%)', $visits, $sales, $conv);
        $rules = $active !== [] ? ' — regras: ' . implode('; ', $active) : '';

        return $prefix . $metrics . $rules;
    }

    /** @param array<string, mixed> $item */
    private function primaryAction(array $item): string
    {
        $actions = $item['actions'] ?? [];
        if (is_array($actions) && $actions !== []) {
            $top = $actions[0];
            $tipo = (string) ($top['tipo'] ?? $top['type'] ?? 'REVISAR');
            $porquê = (string) ($top['porque'] ?? $top['description'] ?? '');
            return trim($tipo . ($porquê !== '' ? ' — ' . $porquê : ''))
                . ' (executar manualmente no painel do Mercado Livre)';
        }

        return match ((string) ($item['classification'] ?? '')) {
            AccountGovernanceService::CLASS_TOXICO,
            AccountGovernanceService::CLASS_MORTO => 'Pausar anúncio no painel ML',
            AccountGovernanceService::CLASS_POLUIDOR => 'Otimizar título/preço no painel ML',
            AccountGovernanceService::CLASS_SEM_ESTOQUE => 'Repor estoque ou pausar no painel ML',
            default => 'Revisar anúncio no painel ML',
        };
    }

    /** @param array<string, bool> $flags */
    private function impactForClass(string $class, array $flags): int
    {
        return match ($class) {
            AccountGovernanceService::CLASS_TOXICO => 50 + (($flags['HIGH_TRAFFIC'] ?? false) ? 15 : 0),
            AccountGovernanceService::CLASS_SEM_ESTOQUE => 45,
            AccountGovernanceService::CLASS_POLUIDOR => 30,
            AccountGovernanceService::CLASS_MORTO => 20,
            AccountGovernanceService::CLASS_EM_RISCO => 18,
            AccountGovernanceService::CLASS_FRACO => 12,
            default => 10,
        };
    }

    private function priorityForClass(string $class): string
    {
        return match ($class) {
            AccountGovernanceService::CLASS_TOXICO,
            AccountGovernanceService::CLASS_SEM_ESTOQUE => 'CRITICA',
            AccountGovernanceService::CLASS_POLUIDOR,
            AccountGovernanceService::CLASS_EM_RISCO => 'ALTA',
            default => 'MEDIA',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadCatalogBlockers(): array
    {
        if ($this->accountId === null) {
            return [];
        }

        try {
            // Inclui blockers ainda "abertos" OU itens que seguem under_review no cache local
            // (resolved_at pode ter sido marcado cedo demais pelo coletor).
            $stmt = $this->db->prepare(
                'SELECT b.item_id, b.reason, b.remedy, b.performance_json, b.scanned_at, b.resolved_at,
                        mi.status AS item_status, mi.title AS item_title
                 FROM ml_sales_blockers b
                 LEFT JOIN ml_items mi
                   ON CONVERT(mi.ml_item_id USING utf8mb4) = CONVERT(b.item_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
                  AND mi.account_id = b.account_id
                 WHERE b.account_id = ?
                   AND (b.resolved_at IS NULL OR mi.status IN (\'under_review\', \'inactive\'))
                 ORDER BY b.scanned_at DESC'
            );
            $stmt->execute([$this->accountId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $itemId = strtoupper((string) $row['item_id']);
            if ($itemId === '' || isset($seen[$itemId])) {
                continue;
            }
            $seen[$itemId] = true;
            $perf = $this->decodeJson($row['performance_json'] ?? null) ?? [];
            $playbook = is_array($perf['playbook'] ?? null) ? $perf['playbook'] : [];
            $snapshot = is_array($perf['item_snapshot'] ?? null) ? $perf['item_snapshot'] : [];
            $out[] = [
                'item_id' => $itemId,
                'reason' => $row['reason'],
                'remedy' => $row['remedy'],
                'title' => (string) ($row['item_title'] ?? $snapshot['title'] ?? $itemId),
                'playbook' => $playbook,
                'seller_edit_url' => $playbook['seller_edit_url'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchModerationDetail(string $mlbId): ?array
    {
        if ($this->accountId === null) {
            return null;
        }

        $client = $this->client ?? new MercadoLivreClient($this->accountId);

        // Preferir API; se falhar, usar cache local. Sempre tenta local para MLB em moderação.
        try {
            $item = $client->get('/items/' . $mlbId, [], 60);
            if (!is_array($item) || isset($item['error'])) {
                return $this->moderationFromLocal($mlbId);
            }

            $status = (string) ($item['status'] ?? '');
            $subStatus = $item['sub_status'] ?? [];
            if (!is_array($subStatus)) {
                $subStatus = $subStatus !== null && $subStatus !== '' ? [(string) $subStatus] : [];
            }
            $tags = is_array($item['tags'] ?? null) ? $item['tags'] : [];
            $warnings = $item['warnings'] ?? null;
            $title = (string) ($item['title'] ?? $mlbId);

            $reasons = [];
            if ($status === 'under_review') {
                $reasons[] = 'status=under_review (em moderação/revisão pelo ML)';
            }
            if ($subStatus !== []) {
                $reasons[] = 'sub_status: ' . implode(', ', array_map('strval', $subStatus));
            }
            if (in_array('forbidden', $subStatus, true)) {
                $reasons[] = 'ML marcou o anúncio como forbidden (bloqueado) — tipicamente violação de política, duplicidade ou catálogo';
            }
            if (in_array('waiting_for_patch', $subStatus, true)) {
                $reasons[] = 'aguardando correção (waiting_for_patch) — geralmente vínculo de catálogo ou ajuste de ficha';
            }
            if (in_array('catalog_listing_eligible', $tags, true) && empty($item['catalog_listing'])) {
                $reasons[] = 'elegível a catálogo mas não vinculado (catalog_listing=false)';
            }
            if (!empty($item['catalog_listing']) && !empty($item['catalog_product_id'])) {
                $reasons[] = 'vinculado ao catálogo ' . $item['catalog_product_id'] . ' mas ainda under_review';
            }
            if (is_array($warnings) && $warnings !== []) {
                $reasons[] = 'warnings: ' . json_encode($warnings, JSON_UNESCAPED_UNICODE);
            }

            if ($reasons === [] && $status !== 'under_review') {
                return $this->moderationFromLocal($mlbId);
            }

            $catalogProductId = $item['catalog_product_id'] ?? null;
            $action = 'Abrir a publicação no painel ML, ler o motivo exibido na tela de moderação e corrigir/republicar';
            if (in_array('forbidden', $subStatus, true)) {
                $action = 'No painel ML: abrir ' . $mlbId . ' → aba de moderação → ler a infração exata (forbidden) → corrigir o motivo apontado (conteúdo/política/catálogo) ou remover e republicar conforme orientação do ML'
                    . ($catalogProductId ? " · catálogo vinculado: {$catalogProductId}" : '');
            } elseif (in_array('waiting_for_patch', $subStatus, true)) {
                $action = 'No painel ML: abrir publicação → aceitar/vincular produto de catálogo sugerido'
                    . ($catalogProductId ? " ({$catalogProductId})" : '')
                    . ' → republicar para sair de waiting_for_patch';
            }

            return [
                'mlb_id' => $mlbId,
                'title' => $title,
                'status' => $status,
                'sub_status' => $subStatus,
                'tags' => $tags,
                'catalog_product_id' => $catalogProductId,
                'permalink' => $item['permalink'] ?? null,
                'seller_edit_url' => 'https://www.mercadolivre.com.br/publicacoes/' . $mlbId . '/modificar',
                'reason_display' => implode(' · ', $reasons) ?: 'Em moderação (detalhe indisponível na API)',
                'recommended_action' => $action,
                'source' => 'ml_api_items',
            ];
        } catch (Throwable $e) {
            log_warning('AccountUnlockPlanService: falha ao consultar moderação', [
                'mlb_id' => $mlbId,
                'error' => $e->getMessage(),
            ]);
            return $this->moderationFromLocal($mlbId);
        }
    }

    /** @return array<string, mixed>|null */
    private function moderationFromLocal(string $mlbId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT ml_item_id, title, status, `data`, catalog_product_id, permalink
                 FROM ml_items
                 WHERE account_id = ? AND ml_item_id = ? LIMIT 1'
            );
            $stmt->execute([$this->accountId, $mlbId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $data = $this->decodeJson($row['data'] ?? null) ?? [];
            $sub = $data['sub_status'] ?? [];
            if (!is_array($sub)) {
                $sub = $sub !== null && $sub !== '' ? [(string) $sub] : [];
            }
            $status = (string) $row['status'];
            $reasons = ['status=' . $status];
            if ($sub !== []) {
                $reasons[] = 'sub_status: ' . implode(', ', array_map('strval', $sub));
            }
            if (in_array('waiting_for_patch', $sub, true)) {
                $reasons[] = 'aguardando correção (waiting_for_patch)';
            }
            $catalogProductId = $row['catalog_product_id'] ?? ($data['catalog_product_id'] ?? null);
            if ($catalogProductId) {
                $reasons[] = 'catalog_product_id=' . $catalogProductId;
            }

            $action = 'Abrir publicação no painel ML e seguir as correções pedidas pela moderação';
            if (in_array('waiting_for_patch', $sub, true) || $status === 'under_review') {
                $action = 'No painel ML: abrir publicação → verificar motivo da moderação'
                    . ($catalogProductId ? " / vincular catálogo {$catalogProductId}" : '')
                    . ' → corrigir e republicar';
            }

            return [
                'mlb_id' => $mlbId,
                'title' => (string) ($row['title'] ?: $mlbId),
                'status' => $status,
                'sub_status' => $sub,
                'tags' => $data['tags'] ?? [],
                'catalog_product_id' => $catalogProductId,
                'permalink' => $row['permalink'] ?? ($data['permalink'] ?? null),
                'seller_edit_url' => 'https://www.mercadolivre.com.br/publicacoes/' . $mlbId . '/modificar',
                'reason_display' => implode(' · ', $reasons),
                'recommended_action' => $action,
                'source' => 'ml_items_local',
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return array<string, true> */
    private function loadResolvedMap(): array
    {
        if ($this->accountId === null) {
            return [];
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT mlb_id FROM account_unlock_plan_items
                 WHERE account_id = ? AND status = \'resolved\''
            );
            $stmt->execute([$this->accountId]);
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $mlb) {
                $map[strtoupper((string) $mlb)] = true;
            }
            return $map;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function persistPlan(array $items, ?string $reportId): void
    {
        if ($this->accountId === null) {
            return;
        }

        $sql = 'INSERT INTO account_unlock_plan_items
                  (account_id, report_id, mlb_id, title, classification, reason, recommended_action,
                   impact_score, priority, status, moderation_detail, catalog_blocker, source, updated_at, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
                ON DUPLICATE KEY UPDATE
                  report_id = VALUES(report_id),
                  title = VALUES(title),
                  classification = VALUES(classification),
                  reason = VALUES(reason),
                  recommended_action = VALUES(recommended_action),
                  impact_score = VALUES(impact_score),
                  priority = VALUES(priority),
                  moderation_detail = VALUES(moderation_detail),
                  catalog_blocker = VALUES(catalog_blocker),
                  source = VALUES(source),
                  updated_at = NOW(),
                  status = IF(status = \'resolved\', \'resolved\', VALUES(status))';

        $stmt = $this->db->prepare($sql);
        foreach ($items as $item) {
            $stmt->execute([
                $this->accountId,
                $reportId,
                $item['mlb_id'],
                mb_substr((string) $item['title'], 0, 255),
                $item['classification'],
                $item['reason'],
                $item['recommended_action'],
                (int) $item['impact_score'],
                $item['priority'],
                $item['status'] ?? 'pending',
                $item['moderation_detail'] !== null ? json_encode($item['moderation_detail'], JSON_UNESCAPED_UNICODE) : null,
                $item['catalog_blocker'] !== null ? json_encode($item['catalog_blocker'], JSON_UNESCAPED_UNICODE) : null,
                $item['source'],
            ]);
        }
    }

    /** @return array<string, mixed>|null */
    private function decodeJson(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function ensureSchema(): void
    {
        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS account_unlock_plan_items (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    account_id INT NOT NULL,
                    report_id VARCHAR(64) NULL,
                    mlb_id VARCHAR(32) NOT NULL,
                    title VARCHAR(255) NOT NULL DEFAULT '',
                    classification VARCHAR(32) NOT NULL,
                    reason TEXT NOT NULL,
                    recommended_action TEXT NOT NULL,
                    impact_score INT NOT NULL DEFAULT 0,
                    priority VARCHAR(16) NOT NULL DEFAULT 'MEDIA',
                    status ENUM('pending','resolved') NOT NULL DEFAULT 'pending',
                    moderation_detail JSON NULL,
                    catalog_blocker JSON NULL,
                    source VARCHAR(64) NOT NULL DEFAULT 'governance',
                    note VARCHAR(255) NULL,
                    resolved_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_unlock_account_mlb (account_id, mlb_id),
                    KEY idx_unlock_account_status (account_id, status, impact_score)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable $e) {
            log_warning('AccountUnlockPlanService: ensureSchema falhou', ['error' => $e->getMessage()]);
        }
    }
}
