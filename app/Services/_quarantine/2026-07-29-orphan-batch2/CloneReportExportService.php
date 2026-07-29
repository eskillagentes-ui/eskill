<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use PDO;

/**
 * Service para exportação de relatórios do sistema de clonagem
 *
 * Suporta exportação em múltiplos formatos:
 * - PDF: Relatórios formatados profissionalmente com gráficos
 * - Excel: Planilhas com dados detalhados e filtros
 * - CSV: Dados brutos para análise externa
 *
 * @package App\Services
 */
class CloneReportExportService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Exportar relatório em formato especificado
     *
     * @param string $format 'pdf', 'excel', 'csv'
     * @param array $filters Filtros: account_id, date_from, date_to, status
     * @param array $options Opções: include_charts, include_summary, orientation
     * @return array{success: bool, file_path?: string, download_url?: string, error?: string}
     */
    public function exportReport(string $format, array $filters = [], array $options = []): array
    {
        try {
            // Validar formato
            if (!in_array($format, ['pdf', 'excel', 'csv'])) {
                return ['success' => false, 'error' => 'Formato inválido'];
            }

            // Obter dados do relatório
            $data = $this->getReportData($filters);

            if (empty($data['jobs'])) {
                return ['success' => false, 'error' => 'Nenhum dado encontrado para os filtros especificados'];
            }

            // Gerar arquivo conforme formato
            switch ($format) {
                case 'pdf':
                    return $this->exportToPdf($data, $options);

                case 'excel':
                    return $this->exportToExcel($data, $options);

                case 'csv':
                    return $this->exportToCsv($data);

                default:
                    return ['success' => false, 'error' => 'Formato não implementado'];
            }
        } catch (\Exception $e) {
            log_error('Erro ao exportar relatório de clone', [
                'format' => $format,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Obter dados do relatório com filtros
     *
     * @param array $filters
     * @return array{jobs: array, summary: array, charts_data: array}
     */
    private function getReportData(array $filters): array
    {
        $sql = "
            SELECT
                j.id as job_id,
                j.account_id,
                a.nickname as account_name,
                j.seller_id,
                s.nickname as seller_name,
                j.status,
                j.items_total,
                j.items_completed,
                j.items_failed,
                j.items_pending,
                j.created_at,
                j.completed_at,
                TIMESTAMPDIFF(SECOND, j.created_at, j.completed_at) as duration_seconds,
                j.metadata
            FROM catalog_clone_jobs j
            LEFT JOIN ml_accounts a ON j.account_id = a.id
            WHERE 1=1
        ";

        $params = [];

        // Aplicar filtros
        if (!empty($filters['account_id'])) {
            $sql .= " AND j.account_id = :account_id";
            $params['account_id'] = $filters['account_id'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND j.created_at >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND j.created_at <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $placeholders = [];
                foreach ($filters['status'] as $i => $status) {
                    $key = "status_{$i}";
                    $placeholders[] = ":{$key}";
                    $params[$key] = $status;
                }
                $sql .= " AND j.status IN (" . implode(',', $placeholders) . ")";
            } else {
                $sql .= " AND j.status = :status";
                $params['status'] = $filters['status'];
            }
        }

        $sql .= " ORDER BY j.created_at DESC";

        // Limite máximo de registros para evitar exports gigantes
        $requestedLimit = (int)($filters['limit'] ?? 1000);
        $limitSql = max(1, min(5000, $requestedLimit));
        $sql .= " LIMIT {$limitSql}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calcular sumário
        $summary = $this->calculateSummary($jobs);

        // Preparar dados para gráficos
        $chartsData = $this->prepareChartsData($jobs);

        return [
            'jobs' => $jobs,
            'summary' => $summary,
            'charts_data' => $chartsData,
        ];
    }

    /**
     * Calcular sumário estatístico
     *
     * @param array $jobs
     * @return array
     */
    private function calculateSummary(array $jobs): array
    {
        $totalJobs = count($jobs);
        $totalItems = 0;
        $totalCompleted = 0;
        $totalFailed = 0;
        $totalDuration = 0;
        $statusCounts = [];

        foreach ($jobs as $job) {
            $totalItems += (int)$job['items_total'];
            $totalCompleted += (int)$job['items_completed'];
            $totalFailed += (int)$job['items_failed'];

            if ($job['duration_seconds']) {
                $totalDuration += (int)$job['duration_seconds'];
            }

            $status = $job['status'];
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        }

        $successRate = $totalItems > 0
            ? round(($totalCompleted / $totalItems) * 100, 2)
            : 0;

        $avgDuration = $totalJobs > 0
            ? round($totalDuration / $totalJobs, 2)
            : 0;

        return [
            'total_jobs' => $totalJobs,
            'total_items' => $totalItems,
            'total_completed' => $totalCompleted,
            'total_failed' => $totalFailed,
            'success_rate_pct' => $successRate,
            'avg_duration_seconds' => $avgDuration,
            'status_counts' => $statusCounts,
        ];
    }

    /**
     * Preparar dados para gráficos
     *
     * @param array $jobs
     * @return array
     */
    private function prepareChartsData(array $jobs): array
    {
        // Agrupar por data
        $byDate = [];
        foreach ($jobs as $job) {
            $date = date('Y-m-d', strtotime($job['created_at']));
            if (!isset($byDate[$date])) {
                $byDate[$date] = [
                    'date' => $date,
                    'jobs' => 0,
                    'items_completed' => 0,
                    'items_failed' => 0,
                ];
            }
            $byDate[$date]['jobs']++;
            $byDate[$date]['items_completed'] += (int)$job['items_completed'];
            $byDate[$date]['items_failed'] += (int)$job['items_failed'];
        }

        // Agrupar por status
        $byStatus = [];
        foreach ($jobs as $job) {
            $status = $job['status'];
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        // Agrupar por conta
        $byAccount = [];
        foreach ($jobs as $job) {
            $account = $job['account_name'] ?? 'Unknown';
            if (!isset($byAccount[$account])) {
                $byAccount[$account] = [
                    'account' => $account,
                    'jobs' => 0,
                    'items' => 0,
                ];
            }
            $byAccount[$account]['jobs']++;
            $byAccount[$account]['items'] += (int)$job['items_completed'];
        }

        return [
            'by_date' => array_values($byDate),
            'by_status' => $byStatus,
            'by_account' => array_values($byAccount),
        ];
    }

    /**
     * Exportar para PDF
     *
     * @param array $data
     * @param array $options
     * @return array
     */
    private function exportToPdf(array $data, array $options): array
    {
        // Verificar se TCPDF está disponível
        if (!class_exists('TCPDF')) {
            return $this->exportSimplePdf($data, $options);
        }

        try {
            $pdfClass = 'TCPDF';
            $pdf = new $pdfClass(
                $options['orientation'] ?? 'P',
                'mm',
                'A4',
                true,
                'UTF-8'
            );

            // Configurações do PDF
            $pdf->SetCreator('eskill Clone System');
            $pdf->SetAuthor('eskill');
            $pdf->SetTitle('Relatório de Clonagem em Lote');
            $pdf->SetSubject('Estatísticas e Detalhes');

            // Remover header/footer padrão
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);

            // Margens
            $pdf->SetMargins(15, 15, 15);
            $pdf->SetAutoPageBreak(true, 15);

            // Adicionar página
            $pdf->AddPage();

            // Título
            $pdf->SetFont('helvetica', 'B', 20);
            $pdf->Cell(0, 10, 'Relatório de Clonagem em Lote', 0, 1, 'C');
            $pdf->Ln(5);

            // Período
            $pdf->SetFont('helvetica', '', 10);
            $period = date('d/m/Y H:i');
            $pdf->Cell(0, 5, "Gerado em: {$period}", 0, 1, 'C');
            $pdf->Ln(5);

            // Sumário
            if ($options['include_summary'] ?? true) {
                $this->addSummaryToPdf($pdf, $data['summary']);
            }

            // Tabela de jobs
            $this->addJobsTableToPdf($pdf, $data['jobs']);

            // Salvar arquivo
            $filename = 'clone_report_' . date('Y-m-d_His') . '.pdf';
            $filePath = $this->getStoragePath() . '/' . $filename;
            $pdf->Output($filePath, 'F');

            return [
                'success' => true,
                'file_path' => $filePath,
                'download_url' => '/storage/exports/' . $filename,
                'filename' => $filename,
            ];
        } catch (\Exception $e) {
            log_error('Erro ao gerar PDF de relatório', [
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Exportar PDF simples (sem TCPDF)
     *
     * @param array $data
     * @param array $options
     * @return array
     */
    private function exportSimplePdf(array $data, array $options): array
    {
        // Gerar HTML e usar DomPDF ou similar
        $html = $this->generateReportHtml($data, $options);

        $filename = 'clone_report_' . date('Y-m-d_His') . '.html';
        $filePath = $this->getStoragePath() . '/' . $filename;
        file_put_contents($filePath, $html);

        return [
            'success' => true,
            'file_path' => $filePath,
            'download_url' => '/storage/exports/' . $filename,
            'filename' => $filename,
            'note' => 'Gerado como HTML. Instale TCPDF para PDFs profissionais.',
        ];
    }

    /**
     * Adicionar sumário ao PDF
     *
     * @param \TCPDF $pdf
     * @param array $summary
     * @return void
     */
    private function addSummaryToPdf($pdf, array $summary): void
    {
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 8, 'Resumo Executivo', 0, 1);
        $pdf->Ln(2);

        $pdf->SetFont('helvetica', '', 10);

        $summaryData = [
            ['Total de Jobs', $summary['total_jobs']],
            ['Total de Itens', $summary['total_items']],
            ['Itens Clonados', $summary['total_completed']],
            ['Itens com Falha', $summary['total_failed']],
            ['Taxa de Sucesso', $summary['success_rate_pct'] . '%'],
            ['Duração Média', $this->formatDuration($summary['avg_duration_seconds'])],
        ];

        foreach ($summaryData as $row) {
            $pdf->Cell(90, 6, $row[0], 1);
            $pdf->Cell(90, 6, (string)$row[1], 1, 1);
        }

        $pdf->Ln(5);
    }

    /**
     * Adicionar tabela de jobs ao PDF
     *
     * @param \TCPDF $pdf
     * @param array $jobs
     * @return void
     */
    private function addJobsTableToPdf($pdf, array $jobs): void
    {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Detalhamento de Jobs', 0, 1);
        $pdf->Ln(2);

        // Cabeçalho
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(15, 6, 'Job ID', 1, 0, 'C');
        $pdf->Cell(40, 6, 'Conta', 1, 0, 'C');
        $pdf->Cell(20, 6, 'Status', 1, 0, 'C');
        $pdf->Cell(20, 6, 'Total', 1, 0, 'C');
        $pdf->Cell(20, 6, 'OK', 1, 0, 'C');
        $pdf->Cell(20, 6, 'Falhas', 1, 0, 'C');
        $pdf->Cell(45, 6, 'Data', 1, 1, 'C');

        // Dados
        $pdf->SetFont('helvetica', '', 7);
        foreach ($jobs as $job) {
            $pdf->Cell(15, 5, $job['job_id'], 1, 0, 'C');
            $pdf->Cell(40, 5, mb_substr($job['account_name'] ?? 'N/A', 0, 18), 1);
            $pdf->Cell(20, 5, $job['status'], 1, 0, 'C');
            $pdf->Cell(20, 5, $job['items_total'], 1, 0, 'C');
            $pdf->Cell(20, 5, $job['items_completed'], 1, 0, 'C');
            $pdf->Cell(20, 5, $job['items_failed'], 1, 0, 'C');
            $pdf->Cell(45, 5, date('d/m/Y H:i', strtotime($job['created_at'])), 1, 1);
        }
    }

    /**
     * Exportar para Excel
     *
     * @param array $data
     * @param array $options
     * @return array
     */
    private function exportToExcel(array $data, array $options): array
    {
        // Verificar se PhpSpreadsheet está disponível
        if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            return $this->exportToCsv($data); // Fallback para CSV
        }

        try {
            $spreadsheetClass = 'PhpOffice\\PhpSpreadsheet\\Spreadsheet';
            $spreadsheet = new $spreadsheetClass();

            // Aba 1: Resumo
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Resumo');
            $this->addSummaryToExcel($sheet, $data['summary']);

            // Aba 2: Jobs
            $jobsSheet = $spreadsheet->createSheet();
            $jobsSheet->setTitle('Jobs');
            $this->addJobsToExcel($jobsSheet, $data['jobs']);

            // Aba 3: Gráficos (dados)
            if ($options['include_charts'] ?? true) {
                $chartsSheet = $spreadsheet->createSheet();
                $chartsSheet->setTitle('Dados para Gráficos');
                $this->addChartsDataToExcel($chartsSheet, $data['charts_data']);
            }

            // Salvar arquivo
            $filename = 'clone_report_' . date('Y-m-d_His') . '.xlsx';
            $filePath = $this->getStoragePath() . '/' . $filename;

            $writerClass = 'PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx';
            $writer = new $writerClass($spreadsheet);
            $writer->save($filePath);

            return [
                'success' => true,
                'file_path' => $filePath,
                'download_url' => '/storage/exports/' . $filename,
                'filename' => $filename,
            ];
        } catch (\Exception $e) {
            log_error('Erro ao gerar Excel de relatório', [
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Adicionar sumário ao Excel
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param array $summary
     * @return void
     */
    private function addSummaryToExcel($sheet, array $summary): void
    {
        $sheet->setCellValue('A1', 'Resumo Executivo');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $row = 3;
        $sheet->setCellValue("A{$row}", 'Métrica');
        $sheet->setCellValue("B{$row}", 'Valor');
        $sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);

        $row++;
        $metrics = [
            'Total de Jobs' => $summary['total_jobs'],
            'Total de Itens' => $summary['total_items'],
            'Itens Clonados' => $summary['total_completed'],
            'Itens com Falha' => $summary['total_failed'],
            'Taxa de Sucesso (%)' => $summary['success_rate_pct'],
            'Duração Média (seg)' => $summary['avg_duration_seconds'],
        ];

        foreach ($metrics as $label => $value) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $value);
            $row++;
        }

        // Ajustar largura das colunas
        $sheet->getColumnDimension('A')->setWidth(25);
        $sheet->getColumnDimension('B')->setWidth(20);
    }

    /**
     * Adicionar jobs ao Excel
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param array $jobs
     * @return void
     */
    private function addJobsToExcel($sheet, array $jobs): void
    {
        // Cabeçalhos
        $headers = [
            'A1' => 'Job ID',
            'B1' => 'Conta',
            'C1' => 'Seller',
            'D1' => 'Status',
            'E1' => 'Total Itens',
            'F1' => 'Concluídos',
            'G1' => 'Falhas',
            'H1' => 'Pendentes',
            'I1' => 'Criado Em',
            'J1' => 'Concluído Em',
            'K1' => 'Duração (seg)',
        ];

        foreach ($headers as $cell => $header) {
            $sheet->setCellValue($cell, $header);
        }
        $sheet->getStyle('A1:K1')->getFont()->setBold(true);

        // Dados
        $row = 2;
        foreach ($jobs as $job) {
            $sheet->setCellValue("A{$row}", $job['job_id']);
            $sheet->setCellValue("B{$row}", $job['account_name'] ?? 'N/A');
            $sheet->setCellValue("C{$row}", $job['seller_name'] ?? 'N/A');
            $sheet->setCellValue("D{$row}", $job['status']);
            $sheet->setCellValue("E{$row}", $job['items_total']);
            $sheet->setCellValue("F{$row}", $job['items_completed']);
            $sheet->setCellValue("G{$row}", $job['items_failed']);
            $sheet->setCellValue("H{$row}", $job['items_pending']);
            $sheet->setCellValue("I{$row}", $job['created_at']);
            $sheet->setCellValue("J{$row}", $job['completed_at'] ?? 'N/A');
            $sheet->setCellValue("K{$row}", $job['duration_seconds'] ?? 0);
            $row++;
        }

        // Ajustar largura
        foreach (range('A', 'K') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * Adicionar dados de gráficos ao Excel
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
     * @param array $chartsData
     * @return void
     */
    private function addChartsDataToExcel($sheet, array $chartsData): void
    {
        $sheet->setCellValue('A1', 'Jobs por Data');
        $sheet->getStyle('A1')->getFont()->setBold(true);

        $row = 2;
        $sheet->setCellValue("A{$row}", 'Data');
        $sheet->setCellValue("B{$row}", 'Jobs');
        $sheet->setCellValue("C{$row}", 'Clonados');
        $sheet->setCellValue("D{$row}", 'Falhas');

        $row = 3;
        foreach ($chartsData['by_date'] as $data) {
            $sheet->setCellValue("A{$row}", $data['date']);
            $sheet->setCellValue("B{$row}", $data['jobs']);
            $sheet->setCellValue("C{$row}", $data['items_completed']);
            $sheet->setCellValue("D{$row}", $data['items_failed']);
            $row++;
        }
    }

    /**
     * Exportar para CSV
     *
     * @param array $data
     * @return array
     */
    private function exportToCsv(array $data): array
    {
        try {
            $filename = 'clone_report_' . date('Y-m-d_His') . '.csv';
            $filePath = $this->getStoragePath() . '/' . $filename;

            $fp = fopen($filePath, 'w');
            if ($fp === false) {
                throw new \RuntimeException("Não foi possível abrir arquivo: {$filePath}");
            }

            try {
                // BOM para UTF-8
                fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));

                // Cabeçalho
                fputcsv($fp, [
                    'Job ID',
                    'Conta',
                    'Seller',
                    'Status',
                    'Total Itens',
                    'Concluídos',
                    'Falhas',
                    'Pendentes',
                    'Criado Em',
                    'Concluído Em',
                    'Duração (seg)',
                ]);

                // Dados
                foreach ($data['jobs'] as $job) {
                    fputcsv($fp, [
                        $job['job_id'],
                        $job['account_name'] ?? 'N/A',
                        $job['seller_name'] ?? 'N/A',
                        $job['status'],
                        $job['items_total'],
                        $job['items_completed'],
                        $job['items_failed'],
                        $job['items_pending'],
                        $job['created_at'],
                        $job['completed_at'] ?? 'N/A',
                        $job['duration_seconds'] ?? 0,
                    ]);
                }
            } finally {
                fclose($fp);
            }

            return [
                'success' => true,
                'file_path' => $filePath,
                'download_url' => '/storage/exports/' . $filename,
                'filename' => $filename,
            ];
        } catch (\Exception $e) {
            log_error('Erro ao gerar CSV de relatório', [
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Gerar HTML para relatório
     *
     * @param array $data
     * @param array $options
     * @return string
     */
    private function generateReportHtml(array $data, array $options): string
    {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Relatório de Clonagem</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .summary { background-color: #f9f9f9; padding: 15px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>Relatório de Clonagem em Lote</h1>
    <p>Gerado em: ' . date('d/m/Y H:i:s') . '</p>';

        if ($options['include_summary'] ?? true) {
            $html .= $this->generateSummaryHtml($data['summary']);
        }

        $html .= $this->generateJobsTableHtml($data['jobs']);

        $html .= '</body></html>';

        return $html;
    }

    /**
     * Gerar HTML do sumário
     *
     * @param array $summary
     * @return string
     */
    private function generateSummaryHtml(array $summary): string
    {
        return '
    <div class="summary">
        <h2>Resumo Executivo</h2>
        <p><strong>Total de Jobs:</strong> ' . $summary['total_jobs'] . '</p>
        <p><strong>Total de Itens:</strong> ' . $summary['total_items'] . '</p>
        <p><strong>Itens Clonados:</strong> ' . $summary['total_completed'] . '</p>
        <p><strong>Itens com Falha:</strong> ' . $summary['total_failed'] . '</p>
        <p><strong>Taxa de Sucesso:</strong> ' . $summary['success_rate_pct'] . '%</p>
    </div>';
    }

    /**
     * Gerar HTML da tabela de jobs
     *
     * @param array $jobs
     * @return string
     */
    private function generateJobsTableHtml(array $jobs): string
    {
        $html = '
    <h2>Detalhamento de Jobs</h2>
    <table>
        <thead>
            <tr>
                <th>Job ID</th>
                <th>Conta</th>
                <th>Status</th>
                <th>Total</th>
                <th>OK</th>
                <th>Falhas</th>
                <th>Data</th>
            </tr>
        </thead>
        <tbody>';

        foreach ($jobs as $job) {
            $html .= '
            <tr>
                <td>' . $job['job_id'] . '</td>
                <td>' . htmlspecialchars($job['account_name'] ?? 'N/A') . '</td>
                <td>' . $job['status'] . '</td>
                <td>' . $job['items_total'] . '</td>
                <td>' . $job['items_completed'] . '</td>
                <td>' . $job['items_failed'] . '</td>
                <td>' . date('d/m/Y H:i', strtotime($job['created_at'])) . '</td>
            </tr>';
        }

        $html .= '
        </tbody>
    </table>';

        return $html;
    }

    /**
     * Obter caminho do diretório de storage
     *
     * @return string
     */
    private function getStoragePath(): string
    {
        $path = __DIR__ . '/../../storage/exports';

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        return $path;
    }

    /**
     * Formatar duração em segundos
     *
     * @param float $seconds
     * @return string
     */
    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds, 1) . 's';
        }

        if ($seconds < 3600) {
            return round($seconds / 60, 1) . 'min';
        }

        return round($seconds / 3600, 1) . 'h';
    }

    /**
     * Agendar relatório periódico
     *
     * @param string $format
     * @param array $filters
     * @param string $schedule 'daily', 'weekly', 'monthly'
     * @param array $recipients Emails para envio
     * @return array{success: bool, schedule_id?: int, error?: string}
     */
    public function scheduleReport(string $format, array $filters, string $schedule, array $recipients): array
    {
        try {
            $sql = "
                INSERT INTO scheduled_reports
                (format, filters, schedule_type, recipients, created_at)
                VALUES (:format, :filters, :schedule, :recipients, NOW())
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'format' => $format,
                'filters' => json_encode($filters),
                'schedule' => $schedule,
                'recipients' => json_encode($recipients),
            ]);

            return [
                'success' => true,
                'schedule_id' => (int)$this->db->lastInsertId(),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
