<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;
use RuntimeException;

/**
 * Integração com Brand Protection Program (BPP / PPPI) do Mercado Livre.
 *
 * Requer adesão como Membro do programa E token OAuth autorizado nas APIs PPPI.
 * Direitos ativos no portal web NÃO garantem automaticamente acesso à API.
 *
 * Endpoints (MCP docs: membros-do-programa):
 * - GET  /moderations/pppi/denounces/{SITE}/ITM/options
 * - POST /moderations/pppi/denounces/items/{ITEM_ID}
 * - GET  /moderations/pppi/case/{DENOUNCE_ID}
 * - POST /moderations/pppi/case/{DENOUNCE_ID}
 */
class AwaBrandProtectionService
{
    public const SITE_ID = 'MLB';

    private int $accountId;
    private MercadoLivreClient $client;
    private LoggingService $logger;
    private PDO $db;

    public function __construct(int $accountId, ?MercadoLivreClient $client = null, ?LoggingService $logger = null)
    {
        if ($accountId <= 0) {
            throw new RuntimeException('Conta ML inválida para AwaBrandProtectionService.');
        }

        $this->accountId = $accountId;
        $this->client = $client ?? new MercadoLivreClient($accountId);
        $this->logger = $logger ?? new LoggingService();
        $this->db = Database::getInstance();
    }

    /**
     * BPP fica desligado por padrão (AWA_BPP_ENABLED=false).
     */
    public static function isEnabled(): bool
    {
        $raw = $_ENV['AWA_BPP_ENABLED'] ?? getenv('AWA_BPP_ENABLED') ?: 'false';
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Status operacional completo: API member + direitos cadastrados no portal/DB.
     *
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        if (!self::isEnabled()) {
            return [
                'account_id' => $this->accountId,
                'enabled' => false,
                'api_member' => false,
                'portal_rights_active' => false,
                'ready_to_denounce' => false,
                'membership' => [
                    'eligible' => false,
                    'member' => false,
                    'http_status' => null,
                    'reasons' => [],
                    'message' => 'Brand Protection (BPP) está desligado (AWA_BPP_ENABLED=false).',
                    'enroll_url' => 'https://www.mercadolivre.com.br/noindex/pppi/rights/enroll',
                    'docs' => $this->documentationLinks(),
                ],
                'registered_rights' => [],
                'recommended_reasons' => [],
                'next_steps' => ['BPP permanece off até reativação explícita via AWA_BPP_ENABLED=true.'],
                'enforcement_url' => 'https://www.mercadolivre.com.br/brandprotection/enforcement',
                'enroll_url' => 'https://www.mercadolivre.com.br/noindex/pppi/rights/enroll',
            ];
        }

        $membership = $this->checkMembership();
        $rights = $this->listRegisteredRights();

        $portalActive = false;
        foreach ($rights as $right) {
            if (strtolower((string) ($right['status'] ?? '')) === 'active') {
                $portalActive = true;
                break;
            }
        }

        $nextSteps = [];
        if ($portalActive && !$membership['member']) {
            $nextSteps[] = 'Direito ativo no portal BPP, mas o token OAuth da conta FACILYTY recebe 403 na API PPPI.';
            $nextSteps[] = 'Confirme que o login do portal Brand Protection é a mesma conta FACILYTY (ml_user_id 3058804121).';
            $nextSteps[] = 'No Developers, reautorize o app MCP facilyty e valide permissões/escopos do usuário Membro.';
            $nextSteps[] = 'Se o direito foi cadastrado sob outro usuário ML, conecte essa conta ao eskill ou compartilhe membership.';
            $nextSteps[] = 'Portal: https://www.mercadolivre.com.br/brandprotection/enforcement';
        } elseif (!$portalActive) {
            $nextSteps[] = 'Cadastre/ative o direito da marca no portal BPP.';
            $nextSteps[] = 'Inscrição: https://www.mercadolivre.com.br/noindex/pppi/rights/enroll';
        } else {
            $nextSteps[] = 'API autorizada: use denounceItem() / api-awa-bpp-denounce.php para denunciar itens triados.';
        }

        return [
            'account_id' => $this->accountId,
            'api_member' => (bool) $membership['member'],
            'portal_rights_active' => $portalActive,
            'ready_to_denounce' => (bool) $membership['member'] && $portalActive,
            'membership' => $membership,
            'registered_rights' => $rights,
            'recommended_reasons' => [
                ['id' => 'PPPI1', 'label' => 'Produto falsificado'],
                ['id' => 'PPPI2', 'label' => 'Uso ilegal da marca'],
            ],
            'next_steps' => $nextSteps,
            'enforcement_url' => 'https://www.mercadolivre.com.br/brandprotection/enforcement',
            'enroll_url' => 'https://www.mercadolivre.com.br/noindex/pppi/rights/enroll',
        ];
    }

    /**
     * Verifica se a conta autenticada tem acesso de Membro BPP via API.
     *
     * @return array{
     *   eligible:bool,
     *   member:bool,
     *   http_status:?int,
     *   reasons:list<array<string,mixed>>,
     *   message:string,
     *   enroll_url:string,
     *   docs:list<string>,
     *   raw_error?:mixed,
     *   api_message?:?string
     * }
     */
    public function checkMembership(): array
    {
        if (!self::isEnabled()) {
            return [
                'eligible' => false,
                'member' => false,
                'http_status' => null,
                'reasons' => [],
                'message' => 'Brand Protection (BPP) está desligado (AWA_BPP_ENABLED=false).',
                'enroll_url' => 'https://www.mercadolivre.com.br/noindex/pppi/rights/enroll',
                'docs' => $this->documentationLinks(),
                'api_message' => 'disabled',
            ];
        }

        $response = $this->client->get(
            '/moderations/pppi/denounces/' . self::SITE_ID . '/ITM/options',
            [],
            60,
            false
        );

        $status = (int) ($response['status'] ?? $response['http_status'] ?? 0);
        $error = $response['error'] ?? null;
        $apiMessage = isset($response['message']) ? (string) $response['message'] : null;

        $isList = is_array($response)
            && $error === null
            && array_is_list($response)
            && isset($response[0]['id']);

        if (!$isList && isset($response['body']) && is_array($response['body']) && array_is_list($response['body'])) {
            $response = $response['body'];
            $isList = isset($response[0]['id']);
        }

        if ($isList) {
            return [
                'eligible' => true,
                'member' => true,
                'http_status' => $status > 0 ? $status : 200,
                'reasons' => array_values(array_filter($response, 'is_array')),
                'message' => 'Conta autenticada é Membro do Brand Protection Program e pode denunciar via API.',
                'enroll_url' => 'https://www.mercadolivre.com.br/noindex/pppi/rights/enroll',
                'docs' => $this->documentationLinks(),
                'api_message' => null,
            ];
        }

        $forbidden = $status === 403
            || in_array((string) $error, ['forbidden', 'access_denied', 'unauthorized', 'http_error'], true);

        $message = 'Não foi possível confirmar membership BPP via API.';
        if ($forbidden && $apiMessage !== null && stripos($apiMessage, 'not authorized') !== false) {
            $message = 'API PPPI: User not Authorized (403). Direito pode estar Ativo no portal web, '
                . 'mas o token OAuth desta conta ainda não está autorizado a denunciar. '
                . 'Verifique se o login do Brand Protection é a mesma conta FACILYTY e se o app foi reautorizado.';
        } elseif ($forbidden) {
            $message = 'Conta não é Membro do BPP (ou token sem escopo). Adira/ative membership antes de usar as APIs de denúncia.';
        } elseif ($apiMessage !== null) {
            $message = 'Não foi possível confirmar membership BPP: ' . $apiMessage;
        }

        return [
            'eligible' => false,
            'member' => false,
            'http_status' => $status > 0 ? $status : null,
            'reasons' => [],
            'message' => $message,
            'enroll_url' => 'https://www.mercadolivre.com.br/noindex/pppi/rights/enroll',
            'docs' => $this->documentationLinks(),
            'raw_error' => $error,
            'api_message' => $apiMessage,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRegisteredRights(): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT id, account_id, right_name, country, site_id, right_type, subtype,
                        registration_number, classes, limitation, status, valid_until,
                        portal_url, notes, updated_at
                   FROM awa_bpp_registered_rights
                  WHERE account_id = :account_id
                  ORDER BY status = \'active\' DESC, right_name ASC'
            );
            $stmt->execute(['account_id' => $this->accountId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (is_array($rows) && $rows !== []) {
                return $rows;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('AWA_BPP_RIGHTS', 'Falha ao listar direitos BPP (tabela ausente?)', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback estático confirmado no portal em 2026-08-03
        if ($this->accountId === 1335) {
            return [[
                'id' => null,
                'account_id' => 1335,
                'right_name' => 'AWA MOTO COMPONENTES',
                'country' => 'Brasil',
                'site_id' => 'MLB',
                'right_type' => 'Marca',
                'subtype' => 'Mista',
                'registration_number' => '900058269',
                'classes' => '12',
                'limitation' => 'ESPELHOS RETROVISORES; ESTRIBOS DE VEÍCULOS / REARVIEW MIRRORS; VEHICLE STIRRUPS.',
                'status' => 'active',
                'valid_until' => '2029-08-25',
                'portal_url' => 'https://www.mercadolivre.com.br/brandprotection/enforcement',
                'notes' => 'Fallback: confirmado no portal BPP (Direitos cadastrados).',
                'updated_at' => null,
            ]];
        }

        return [];
    }

    /**
     * Cria denúncia BPP de um item (somente se membro). Use dryRun=true para simular.
     *
     * @return array<string, mixed>
     */
    public function denounceItem(
        string $itemId,
        string $reportReasonId,
        string $comment = '',
        bool $dryRun = true,
        ?int $sellerRegistryId = null,
        ?string $createdBy = null
    ): array {
        $itemId = strtoupper(trim($itemId));
        $reportReasonId = strtoupper(trim($reportReasonId));
        if ($itemId === '' || !preg_match('/^MLB\d+$/', $itemId)) {
            throw new RuntimeException('item_id inválido (esperado MLB...).');
        }
        if ($reportReasonId === '') {
            throw new RuntimeException('report_reason_id é obrigatório (ex.: PPPI1, PPPI2).');
        }

        if (!self::isEnabled()) {
            return [
                'success' => false,
                'dry_run' => $dryRun,
                'error' => 'bpp_disabled',
                'message' => 'Brand Protection (BPP) está desligado (AWA_BPP_ENABLED=false).',
            ];
        }

        $membership = $this->checkMembership();
        $payload = [
            'report_reason_id' => $reportReasonId,
            'comment' => $comment !== '' ? $comment : 'Denúncia via módulo AWA Sellers (Brand Protection).',
        ];

        if ($dryRun) {
            $result = [
                'success' => true,
                'dry_run' => true,
                'would_call' => '/moderations/pppi/denounces/items/' . $itemId,
                'payload' => $payload,
                'api_member' => (bool) $membership['member'],
                'message' => $membership['member']
                    ? 'Dry-run OK: membership API confirmada; nenhuma denúncia enviada.'
                    : 'Dry-run OK: payload válido, mas membership API ainda bloqueada (403). Nenhuma denúncia enviada.',
                'membership_message' => $membership['message'],
            ];
            $this->persistDenounceLog($itemId, $reportReasonId, $payload['comment'], null, 'dry_run', null, true, $result, $sellerRegistryId, $createdBy);
            return $result;
        }

        if (!$membership['member']) {
            $result = [
                'success' => false,
                'dry_run' => false,
                'error' => 'not_bpp_api_member',
                'message' => $membership['message'],
                'enroll_url' => $membership['enroll_url'],
            ];
            $this->persistDenounceLog($itemId, $reportReasonId, $payload['comment'], null, 'blocked', 403, false, $result, $sellerRegistryId, $createdBy);
            return $result;
        }

        $response = $this->client->post(
            '/moderations/pppi/denounces/items/' . rawurlencode($itemId),
            $payload
        );

        $httpStatus = (int) ($response['status'] ?? $response['http_status'] ?? 0);
        $ok = $httpStatus === 201 || isset($response['denounce_id']);
        $denounceId = isset($response['denounce_id']) ? (int) $response['denounce_id'] : null;

        $this->logger->info('AWA_BPP_DENOUNCE', 'Denúncia BPP enviada', [
            'item_id' => $itemId,
            'reason' => $reportReasonId,
            'success' => $ok,
            'denounce_id' => $denounceId,
        ]);

        $result = [
            'success' => $ok,
            'dry_run' => false,
            'denounce_id' => $denounceId,
            'http_status' => $httpStatus > 0 ? $httpStatus : null,
            'response' => $response,
        ];

        $this->persistDenounceLog(
            $itemId,
            $reportReasonId,
            $payload['comment'],
            $denounceId,
            $ok ? 'submitted' : 'failed',
            $httpStatus > 0 ? $httpStatus : null,
            false,
            $result,
            $sellerRegistryId,
            $createdBy
        );

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCaseStatus(int $denounceId): array
    {
        if ($denounceId <= 0) {
            throw new RuntimeException('denounce_id inválido.');
        }

        return $this->client->get('/moderations/pppi/case/' . $denounceId, [], 60, false);
    }

    /**
     * Responde à documentação do vendedor (somente DOCUMENTATION_PRESENTED).
     *
     * @return array<string, mixed>
     */
    public function respondToCase(int $denounceId, bool $documentationApproved, ?string $memberQuittance = null, ?int $rejectMemberId = null): array
    {
        if ($denounceId <= 0) {
            throw new RuntimeException('denounce_id inválido.');
        }

        $body = [
            'documentation_approved' => $documentationApproved ? 'true' : 'false',
            'member_quittance' => $memberQuittance,
        ];
        if (!$documentationApproved) {
            if ($rejectMemberId === null || $rejectMemberId <= 0) {
                throw new RuntimeException('reject_member_id é obrigatório ao rejeitar documentação.');
            }
            $body['reject_member_id'] = (string) $rejectMemberId;
        }

        return $this->client->post('/moderations/pppi/case/' . $denounceId, $body);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function persistDenounceLog(
        string $itemId,
        string $reportReasonId,
        string $comment,
        ?int $denounceId,
        ?string $apiStatus,
        ?int $httpStatus,
        bool $dryRun,
        array $response,
        ?int $sellerRegistryId,
        ?string $createdBy
    ): void {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO awa_bpp_denounces
                    (account_id, item_id, seller_registry_id, report_reason_id, comment,
                     denounce_id, api_status, http_status, dry_run, response_json, created_by)
                 VALUES
                    (:account_id, :item_id, :seller_registry_id, :report_reason_id, :comment,
                     :denounce_id, :api_status, :http_status, :dry_run, :response_json, :created_by)'
            );
            $stmt->execute([
                'account_id' => $this->accountId,
                'item_id' => $itemId,
                'seller_registry_id' => $sellerRegistryId,
                'report_reason_id' => $reportReasonId,
                'comment' => $comment,
                'denounce_id' => $denounceId,
                'api_status' => $apiStatus,
                'http_status' => $httpStatus,
                'dry_run' => $dryRun ? 1 : 0,
                'response_json' => json_encode($response, JSON_UNESCAPED_UNICODE),
                'created_by' => $createdBy ?? 'system',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('AWA_BPP_DENOUNCE_LOG', 'Falha ao persistir log de denúncia', [
                'error' => $e->getMessage(),
                'item_id' => $itemId,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function documentationLinks(): array
    {
        return [
            'https://developers.mercadolivre.com.br/pt_br/o-que-e-brand-protection-program',
            'https://developers.mercadolivre.com.br/pt_br/membros-do-programa',
            'https://developers.mercadolivre.com.br/pt_br/publicacoes-denunciadas',
            'https://www.mercadolivre.com.br/brandprotection/enforcement',
            'https://www.mercadolivre.com.br/noindex/pppi/rights/enroll',
        ];
    }
}
