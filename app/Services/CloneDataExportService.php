<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

/**
 * CloneDataExportService
 *
 * Exportação avançada de dados de clones.
 * Suporta CSV, JSON, Excel e relatórios customizados.
 */
class CloneDataExportService
{
    private PDO $db;
    private int $accountId;
    private string $exportPath;

    public function __construct(int $accountId)
    {
        $this->db = Database::getInstance();
        $this->accountId = $accountId;
        $this->exportPath = dirname(__DIR__, 2) . '/storage/exports/clone';

        if (!is_dir($this->exportPath)) {
            mkdir($this->exportPath, 0755, true);
        }
    }

    /**
     * Exporta itens clonados para CSV
     */
    public function exportItemsToCsv(array $filters = []): array
    {
        $items = $this->getItemsForExport($filters);

        $filename = $this->buildExportFilename('items', 'csv');
        $filepath = $this->exportPath . '/' . $filename;

        $output = fopen($filepath, 'w');

        // BOM para UTF-8
        fwrite($output, "\xEF\xBB\xBF");

        // Header
        fputcsv($output, [
            'ID',
            'Item ID Clone',
            'Item ID Original',
            'Seller Origem',
            'Título',
            'Preço',
            'Categoria',
            'Status',
            'Visitas',
            'Vendas',
            'Faturamento',
            'Conversão %',
            'Score SEO',
            'Data Criação',
            'Última Sync'
        ], ';');

        foreach ($items as $item) {
            fputcsv($output, [
                $item['id'],
                $item['target_item_id'],
                $item['source_item_id'],
                $item['source_seller_id'],
                $item['title'],
                number_format((float) ($item['price'] ?? 0), 2, ',', '.'),
                $item['category_id'],
                $item['status'],
                $item['visits'] ?? 0,
                $item['sales'] ?? 0,
                number_format((float) ($item['revenue'] ?? 0), 2, ',', '.'),
                number_format((float) ($item['conversion_rate'] ?? 0), 2, ',', '.'),
                $item['seo_score'] ?? '-',
                $item['created_at'],
                $item['last_synced_at'] ?? '-'
            ], ';');
        }

        fclose($output);

        return $this->finalizeExport($filename, $filepath, 'items', 'csv', [
            'total_items' => count($items)
        ], count($items), $filters);
    }

    /**
     * Exporta itens para JSON
     */
    public function exportItemsToJson(array $filters = []): array
    {
        $items = $this->getItemsForExport($filters);

        $filename = $this->buildExportFilename('items', 'json');
        $filepath = $this->exportPath . '/' . $filename;

        $data = [
            'export_date' => date('c'),
            'account_id' => $this->accountId,
            'filters' => $filters,
            'total_items' => count($items),
            'items' => $items
        ];

        file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $this->finalizeExport($filename, $filepath, 'items', 'json', [
            'total_items' => count($items)
        ], count($items), $filters);
    }

    /**
     * Exporta jobs de clone
     */
    public function exportJobsToCsv(array $filters = []): array
    {
        $jobs = $this->getJobsForExport($filters);

        $filename = $this->buildExportFilename('jobs', 'csv');
        $filepath = $this->exportPath . '/' . $filename;

        $output = fopen($filepath, 'w');
        fwrite($output, "\xEF\xBB\xBF");

        fputcsv($output, [
            'Job ID',
            'Tipo Origem',
            'Seller Origem',
            'Status',
            'Total Itens',
            'Processados',
            'Sucesso',
            'Falhas',
            'Taxa Sucesso %',
            'Template',
            'Criado Por',
            'Data Criação',
            'Data Início',
            'Data Conclusão',
            'Duração (min)'
        ], ';');

        foreach ($jobs as $job) {
            $duration = null;
            if ($job['started_at'] && $job['completed_at']) {
                $start = new \DateTime($job['started_at']);
                $end = new \DateTime($job['completed_at']);
                $duration = round($end->getTimestamp() - $start->getTimestamp()) / 60;
            }

            $successRate = $job['processed_items'] > 0
                ? round(($job['successful_items'] / $job['processed_items']) * 100, 2)
                : 0;

            fputcsv($output, [
                $job['job_id'],
                $job['source_type'],
                $job['source_seller_id'] ?? '-',
                $job['status'],
                $job['total_items'],
                $job['processed_items'],
                $job['successful_items'],
                $job['failed_items'],
                $successRate,
                $job['template_name'] ?? 'Padrão',
                $job['created_by_user_id'],
                $job['created_at'],
                $job['started_at'] ?? '-',
                $job['completed_at'] ?? '-',
                $duration !== null ? number_format($duration, 2, ',', '.') : '-'
            ], ';');
        }

        fclose($output);

        return $this->finalizeExport($filename, $filepath, 'jobs', 'csv', [
            'total_jobs' => count($jobs)
        ], count($jobs), $filters);
    }

    /**
     * Exporta métricas agregadas
     */
    public function exportMetrics($options = []): array
    {
        $normalizedOptions = $this->normalizeMetricsOptions($options);
        $days = (int) ($normalizedOptions['days'] ?? 30);

        $metrics = $this->getMetricsForExport($days);

        $filename = $this->buildExportFilename('metrics', 'json');
        $filepath = $this->exportPath . '/' . $filename;

        file_put_contents($filepath, json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $this->finalizeExport($filename, $filepath, 'metrics', 'json', [
            'period_days' => $days
        ], 1, $normalizedOptions);
    }

    /**
     * Exporta relatório completo (HTML)
     */
    public function exportFullReport(array $options = []): array
    {
        $normalizedOptions = $this->normalizeMetricsOptions($options);
        $days = (int) ($normalizedOptions['days'] ?? 30);

        // Coletar dados
        $items = $this->getItemsForExport(['days' => $days]);
        $jobs = $this->getJobsForExport(['days' => $days]);
        $metrics = $this->getMetricsForExport($days);

        // Gerar HTML
        $html = $this->generateHtmlReport($items, $jobs, $metrics, $days);

        $filename = $this->buildExportFilename('report', 'html');
        $filepath = $this->exportPath . '/' . $filename;

        file_put_contents($filepath, $html);

        return $this->finalizeExport($filename, $filepath, 'report', 'html', [], count($items), $normalizedOptions);
    }

    /**
     * Gera relatório HTML
     */
    private function generateHtmlReport(array $items, array $jobs, array $metrics, int $days): string
    {
        $totalItems = count($items);
        $totalJobs = count($jobs);
        $totalRevenue = array_sum(array_column($items, 'revenue'));
        $totalSales = array_sum(array_column($items, 'sales'));

        $html = '<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Relatório de Clonagem - ' . date('d/m/Y') . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; color: #333; }
        h1 { color: #2c5282; border-bottom: 2px solid #2c5282; padding-bottom: 10px; }
        h2 { color: #4a5568; margin-top: 30px; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin: 20px 0; }
        .stat-card { background: #f7fafc; border-radius: 8px; padding: 20px; text-align: center; }
        .stat-value { font-size: 32px; font-weight: bold; color: #2c5282; }
        .stat-label { color: #718096; margin-top: 5px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #edf2f7; font-weight: 600; }
        tr:hover { background: #f7fafc; }
        .status-completed { color: #38a169; }
        .status-failed { color: #e53e3e; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #e2e8f0; color: #718096; font-size: 12px; }
    </style>
</head>
<body>
    <h1>📊 Relatório de Clonagem de Anúncios</h1>
    <p><strong>Período:</strong> Últimos ' . $days . ' dias | <strong>Gerado em:</strong> ' . date('d/m/Y H:i:s') . '</p>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value">' . number_format($totalItems) . '</div>
            <div class="stat-label">Itens Clonados</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">' . number_format($totalJobs) . '</div>
            <div class="stat-label">Jobs Executados</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">' . number_format($totalSales) . '</div>
            <div class="stat-label">Vendas Totais</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">R$ ' . number_format($totalRevenue, 2, ',', '.') . '</div>
            <div class="stat-label">Faturamento Total</div>
        </div>
    </div>

    <h2>📋 Últimos Jobs de Clonagem</h2>
    <table>
        <thead>
            <tr>
                <th>Job ID</th>
                <th>Status</th>
                <th>Itens</th>
                <th>Sucesso</th>
                <th>Falhas</th>
                <th>Data</th>
            </tr>
        </thead>
        <tbody>';

        foreach (array_slice($jobs, 0, 20) as $job) {
            $statusClass = $job['status'] === 'completed' ? 'status-completed' : ($job['status'] === 'failed' ? 'status-failed' : '');

            $html .= '
            <tr>
                <td>' . htmlspecialchars($job['job_id']) . '</td>
                <td class="' . $statusClass . '">' . ucfirst($job['status']) . '</td>
                <td>' . $job['total_items'] . '</td>
                <td>' . $job['successful_items'] . '</td>
                <td>' . $job['failed_items'] . '</td>
                <td>' . date('d/m/Y H:i', strtotime($job['created_at'])) . '</td>
            </tr>';
        }

        $html .= '
        </tbody>
    </table>

    <h2>🏆 Top 10 Itens por Vendas</h2>
    <table>
        <thead>
            <tr>
                <th>Item ID</th>
                <th>Título</th>
                <th>Preço</th>
                <th>Vendas</th>
                <th>Faturamento</th>
            </tr>
        </thead>
        <tbody>';

        usort($items, fn($a, $b) => ($b['sales'] ?? 0) - ($a['sales'] ?? 0));

        foreach (array_slice($items, 0, 10) as $item) {
            $html .= '
            <tr>
                <td>' . htmlspecialchars($item['target_item_id']) . '</td>
                <td>' . htmlspecialchars(mb_substr($item['title'] ?? '', 0, 50)) . '...</td>
                <td>R$ ' . number_format((float) ($item['price'] ?? 0), 2, ',', '.') . '</td>
                <td>' . ($item['sales'] ?? 0) . '</td>
                <td>R$ ' . number_format((float) ($item['revenue'] ?? 0), 2, ',', '.') . '</td>
            </tr>';
        }

        $html .= '
        </tbody>
    </table>

    <div class="footer">
        <p>Relatório gerado automaticamente pelo Sistema de Clonagem eskill.com.br</p>
    </div>
</body>
</html>';

        return $html;
    }

    /**
     * Busca itens para exportação
     */
    private function getItemsForExport(array $filters): array
    {
        $days = (int) ($filters['days'] ?? 30);
        $status = $filters['status'] ?? null;

        $query = "
            SELECT
                ci.*,
                COALESCE(m.visits, 0) as visits,
                COALESCE(m.sales, 0) as sales,
                COALESCE(m.revenue, 0) as revenue,
                COALESCE(m.conversion_rate, 0) as conversion_rate
            FROM cloned_items ci
            LEFT JOIN clone_item_metrics m ON m.item_id = ci.target_item_id
            WHERE ci.target_account_id = :account_id
            AND ci.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
        ";

        $params = [
            'account_id' => $this->accountId,
            'days' => $days
        ];

        if ($status) {
            $query .= " AND ci.status = :status";
            $params['status'] = $status;
        }

        $query .= " ORDER BY ci.created_at DESC";

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca jobs para exportação
     */
    private function getJobsForExport(array $filters): array
    {
        $days = (int) ($filters['days'] ?? 30);

        $stmt = $this->db->prepare("
            SELECT j.*, t.name as template_name
            FROM catalog_clone_jobs j
            LEFT JOIN clone_templates t ON t.id = j.template_id
            WHERE j.target_account_id = :account_id
            AND j.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            ORDER BY j.created_at DESC
        ");
        $stmt->execute([
            'account_id' => $this->accountId,
            'days' => $days
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca métricas para exportação
     */
    private function getMetricsForExport(int $days): array
    {
        // Métricas de itens
        $itemsStmt = $this->db->prepare("
            SELECT
                COUNT(*) as total_items,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                COUNT(DISTINCT category_id) as categories,
                COUNT(DISTINCT source_seller_id) as sellers
            FROM cloned_items
            WHERE target_account_id = :account_id
            AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
        ");
        $itemsStmt->execute(['account_id' => $this->accountId, 'days' => $days]);
        $itemsMetrics = $itemsStmt->fetch(PDO::FETCH_ASSOC);

        // Métricas de jobs
        $jobsStmt = $this->db->prepare("
            SELECT
                COUNT(*) as total_jobs,
                SUM(total_items) as total_items_processed,
                SUM(successful_items) as total_success,
                SUM(failed_items) as total_failed,
                AVG(successful_items * 100.0 / NULLIF(total_items, 0)) as avg_success_rate
            FROM catalog_clone_jobs
            WHERE target_account_id = :account_id
            AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
        ");
        $jobsStmt->execute(['account_id' => $this->accountId, 'days' => $days]);
        $jobsMetrics = $jobsStmt->fetch(PDO::FETCH_ASSOC);

        // Métricas de performance
        $perfStmt = $this->db->prepare("
            SELECT
                SUM(visits) as total_visits,
                SUM(sales) as total_sales,
                SUM(revenue) as total_revenue,
                AVG(conversion_rate) as avg_conversion
            FROM clone_item_metrics
            WHERE account_id = :account_id
        ");
        $perfStmt->execute(['account_id' => $this->accountId]);
        $perfMetrics = $perfStmt->fetch(PDO::FETCH_ASSOC);

        return [
            'export_date' => date('c'),
            'period_days' => $days,
            'items' => $itemsMetrics,
            'jobs' => $jobsMetrics,
            'performance' => $perfMetrics
        ];
    }

    /**
     * Lista exportações disponíveis
     */
    public function listExports(): array
    {
        $exports = [];

        foreach ($this->getExportLogMetadata() as $filename => $metadata) {
            $file = $this->exportPath . '/' . $filename;
            if (!is_file($file)) {
                continue;
            }

            $exports[] = [
                'filename' => $filename,
                'file' => $filename,
                'download_url' => $this->buildDownloadUrl($filename),
                'scope' => (string) ($metadata['export_scope'] ?? $this->inferScopeFromFilename($filename)),
                'format' => (string) ($metadata['export_format'] ?? pathinfo($file, PATHINFO_EXTENSION)),
                'type' => (string) ($metadata['export_format'] ?? pathinfo($file, PATHINFO_EXTENSION)),
                'item_count' => (int) ($metadata['item_count'] ?? 0),
                'size' => filesize($file),
                'size_bytes' => (int) ($metadata['size_bytes'] ?? filesize($file)),
                'created_at' => (string) ($metadata['created_at'] ?? date('c', filemtime($file)))
            ];
        }

        if ($exports === []) {
            $exports = $this->listFallbackExports();
        }

        usort($exports, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));

        return $exports;
    }

    /**
     * Download de arquivo exportado
     */
    public function getExportPath(string $filename): ?string
    {
        $filename = basename($filename);
        $filepath = $this->exportPath . '/' . $filename;

        if (!file_exists($filepath)) {
            return null;
        }

        if (!$this->isOwnedExport($filename)) {
            return null;
        }

        return $filepath;
    }

    /**
     * Remove exportações antigas
     */
    public function cleanOldExports(int $daysToKeep = 7): int
    {
        $files = glob($this->exportPath . '/*');
        $threshold = time() - ($daysToKeep * 86400);
        $deleted = 0;

        foreach ($files as $file) {
            if (filemtime($file) < $threshold) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Registra exportação
     */
    private function finalizeExport(
        string $filename,
        string $filepath,
        string $scope,
        string $format,
        array $payload,
        int $itemCount,
        array $filters = []
    ): array {
        $sizeBytes = filesize($filepath);
        $createdAt = date('c');
        $this->logExport($scope, $format, $filename, $itemCount, $sizeBytes, $filters);

        return array_merge([
            'success' => true,
            'filename' => $filename,
            'file' => $filename,
            'filepath' => $filepath,
            'download_url' => $this->buildDownloadUrl($filename),
            'scope' => $scope,
            'format' => $format,
            'size_bytes' => $sizeBytes,
            'created_at' => $createdAt
        ], $payload);
    }

    private function normalizeMetricsOptions($options): array
    {
        if (is_string($options)) {
            return ['period' => $options, 'days' => $this->parsePeriodToDays($options)];
        }

        if (!is_array($options)) {
            return ['days' => 30];
        }

        if (isset($options['period']) && !isset($options['days'])) {
            $options['days'] = $this->parsePeriodToDays((string) $options['period']);
        }

        return $options;
    }

    private function parsePeriodToDays(string $period): int
    {
        $normalized = strtolower(trim($period));

        if (preg_match('/^(\d+)\s*d$/', $normalized, $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        if (preg_match('/^(\d+)\s*h$/', $normalized, $matches) === 1) {
            return max(1, (int) ceil(((int) $matches[1]) / 24));
        }

        return 30;
    }

    private function buildDownloadUrl(string $filename): string
    {
        return '/api/clone/export/download/' . rawurlencode($filename);
    }

    private function buildExportFilename(string $scope, string $format): string
    {
        return sprintf(
            'clone_%s_a%d_%s.%s',
            $scope,
            $this->accountId,
            date('Y-m-d_His'),
            $format
        );
    }

    private function inferScopeFromFilename(string $filename): string
    {
        if (strpos($filename, 'clone_jobs_') === 0) {
            return 'jobs';
        }

        if (strpos($filename, 'clone_metrics_') === 0) {
            return 'metrics';
        }

        if (strpos($filename, 'clone_report_') === 0) {
            return 'report';
        }

        return 'items';
    }

    private function getExportLogMetadata(): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT filename, export_scope, export_format, item_count, size_bytes, created_at
                FROM clone_export_logs
                WHERE account_id = :account_id
                ORDER BY created_at DESC
                LIMIT 100
            ");
            $stmt->execute(['account_id' => $this->accountId]);

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $metadata = [];

            foreach ($rows as $row) {
                if (!isset($metadata[$row['filename']])) {
                    $metadata[$row['filename']] = $row;
                }
            }

            return $metadata;
        } catch (\Exception $e) {
            return [];
        }
    }

    private function isOwnedExport(string $filename): bool
    {
        if (preg_match('/^clone_[a-z_]+_a' . preg_quote((string) $this->accountId, '/') . '_/', $filename) === 1) {
            return true;
        }

        if (preg_match('/^clone_[a-z_]+_a\d+_/', $filename) === 1) {
            return false;
        }

        $metadata = $this->getExportLogMetadata();

        return isset($metadata[$filename]);
    }

    private function listFallbackExports(): array
    {
        $pattern = $this->exportPath . '/clone_*_a' . $this->accountId . '_*';
        $files = glob($pattern) ?: [];
        $exports = [];

        foreach ($files as $file) {
            $filename = basename($file);
            $exports[] = [
                'filename' => $filename,
                'file' => $filename,
                'download_url' => $this->buildDownloadUrl($filename),
                'scope' => $this->inferScopeFromFilename($filename),
                'format' => pathinfo($file, PATHINFO_EXTENSION),
                'type' => pathinfo($file, PATHINFO_EXTENSION),
                'item_count' => 0,
                'size' => filesize($file),
                'size_bytes' => filesize($file),
                'created_at' => date('c', filemtime($file))
            ];
        }

        return $exports;
    }

    private function logExport(
        string $scope,
        string $format,
        string $filename,
        int $itemCount,
        int $sizeBytes,
        array $filters = []
    ): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO clone_export_logs (
                    account_id, export_scope, export_format, filename, item_count, size_bytes, filters_json, created_at
                ) VALUES (
                    :account_id, :scope, :format, :filename, :count, :size_bytes, :filters_json, NOW()
                )
            ");
            $stmt->execute([
                'account_id' => $this->accountId,
                'scope' => $scope,
                'format' => $format,
                'filename' => $filename,
                'count' => $itemCount,
                'size_bytes' => $sizeBytes,
                'filters_json' => json_encode($filters, JSON_UNESCAPED_UNICODE)
            ]);
        } catch (\Exception $e) {
            log_warning('Falha ao registrar log de exportação de clone', [
                'service' => 'CloneDataExportService',
                'account_id' => $this->accountId,
                'scope' => $scope,
                'format' => $format,
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
