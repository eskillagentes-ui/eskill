<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

/**
 * Serviço de agentes autônomos
 *
 * Gerencia e executa agentes autônomos (Guardian, Sniper, etc.)
 * Tabela: ai_agents
 */
class AutonomousAgentService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Listar agentes registrados
     */
    public function getAgents(): array
    {
        try {
            $stmt = $this->db->query(
                "SELECT * FROM ai_agents ORDER BY name ASC"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Obter logs de execução de agentes
     */
    public function getLogs(?string $agentCode = null, int $limit = 100): array
    {
        try {
            $limitSql = max(1, min(1000, (int)$limit));
            if ($agentCode) {
                $stmt = $this->db->prepare(
                    "SELECT * FROM agent_progress_log
                     WHERE action LIKE :code
                     ORDER BY created_at DESC LIMIT {$limitSql}"
                );
                $stmt->bindValue(':code', "%{$agentCode}%");
                $stmt->execute();
            } else {
                $stmt = $this->db->prepare(
                    "SELECT * FROM agent_progress_log
                     ORDER BY created_at DESC LIMIT {$limitSql}"
                );
                $stmt->execute();
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Executar agente pelo código
     */
    public function runAgent(string $agentCode): array
    {
        try {
            // Mapear agent code para classe
            $agentClass = $this->resolveAgentClass($agentCode);

            if (!$agentClass || !class_exists($agentClass)) {
                return ['success' => false, 'error' => "Agent class not found: {$agentCode}"];
            }

            $statusStmt = $this->db->prepare(
                "SELECT status FROM ai_agents WHERE code = :code LIMIT 1"
            );
            $statusStmt->execute([':code' => $agentCode]);
            $status = $statusStmt->fetchColumn();

            if ($status !== 'active') {
                $statusLabel = $status === false ? 'not_found' : (string)$status;
                $message = "agent {$agentCode} está com status={$statusLabel}, execução ignorada";
                log_warning($message, [
                    'service' => 'AutonomousAgentService',
                    'agent_code' => $agentCode,
                    'status' => $statusLabel,
                ]);

                return [
                    'success' => false,
                    'skipped' => true,
                    'error' => $message,
                    'agent' => $agentCode,
                    'status' => $statusLabel,
                ];
            }

            $agent = new $agentClass();

            if (method_exists($agent, 'execute')) {
                $result = $agent->execute();
            } elseif (method_exists($agent, 'run')) {
                $result = $agent->run();
            } else {
                return ['success' => false, 'error' => 'Agent has no execute/run method'];
            }

            // Log resultado
            $this->logRun($agentCode, true, $result);

            return ['success' => true, 'result' => $result];
        } catch (\Exception $e) {
            $this->logRun($agentCode, false, ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function resolveAgentClass(string $code): ?string
    {
        $map = [
            'guardian' => \App\Agents\GuardianAgent::class,
            'sniper' => \App\Agents\SniperAgent::class,
        ];

        return $map[strtolower($code)] ?? null;
    }

    private function logRun(string $agentCode, bool $success, $details = null): void
    {
        try {
            $this->db->prepare(
                "INSERT INTO agent_progress_log (project_id, action, details, created_at)
                 VALUES (0, :action, :details, NOW())"
            )->execute([
                'action' => "agent_run:{$agentCode}:" . ($success ? 'success' : 'failed'),
                'details' => json_encode($details),
            ]);
        } catch (\Exception $e) {
            log_warning('Erro ao registrar log do agente autônomo', [
                'agent_code' => $agentCode,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
