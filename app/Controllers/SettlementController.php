<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\SettlementService;
use App\Core\Flash;

class SettlementController extends BaseController
{
    private SettlementService $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new SettlementService();
    }

    public function index()
    {
        try {
            $summary = $this->service->getSummary();

            // Transform summary into key-value for easy view access
            $stats = [
                'PENDING' => 0,
                'CONCILIATED' => 0,
                'MISMATCH' => 0,
                'Ignored' => 0,
                'total_amount' => 0
            ];

            if (is_array($summary)) {
                foreach ($summary as $row) {
                    $stats[$row['status']] = $row['count'];
                    if ($row['status'] == 'CONCILIATED') {
                        $stats['total_amount'] += $row['total'];
                    }
                }
            }

            // Get recent items
            $db = \App\Database::getInstance();
            $stmt = $db->query("SELECT * FROM financial_settlements ORDER BY date_released DESC LIMIT 50");
            $recent = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $settlements = $recent;
            $pageTitle = 'Conciliação Financeira';

            // A view aplica o layout (ob_start + include app.php).
            // Não envolver novamente aqui — evita double-render do dashboard.
            require __DIR__ . '/../Views/dashboard/financials/conciliation.php';
        } catch (\Exception $e) {
            log_error('Erro ao carregar conciliação: ' . $e->getMessage());
            Flash::error('Erro ao carregar dados de conciliação. Tente novamente mais tarde.');

            $stats = ['PENDING' => 0, 'CONCILIATED' => 0, 'MISMATCH' => 0, 'total_amount' => 0];
            $settlements = [];
            $error = $e->getMessage();
            $pageTitle = 'Conciliação Financeira';

            require __DIR__ . '/../Views/dashboard/financials/conciliation.php';
        }
    }

    public function upload()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
             header('Location: /dashboard/financials/conciliation');
             exit;
        }

        if (!isset($_FILES['report']) || $_FILES['report']['error'] !== UPLOAD_ERR_OK) {
            Flash::error('Erro no upload do arquivo.');
            header('Location: /dashboard/financials/conciliation');
            exit;
        }

        $file = $_FILES['report']['tmp_name'];
        $fileName = $_FILES['report']['name'];
        $fileSize = $_FILES['report']['size'];

        // 1. Validate Extension
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'])) {
            Flash::error('Apenas arquivos CSV ou TXT são permitidos.');
            header('Location: /dashboard/financials/conciliation');
            exit;
        }

        // 2. Validate MIME Type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file);
        $allowedMimes = [
            'text/plain',
            'text/csv',
            'text/x-csv',
            'application/vnd.ms-excel',
            'application/csv',
            'application/x-csv'
        ];

        if (!in_array($mime, $allowedMimes)) {
            Flash::error('Formato de arquivo inválido (MIME type mismatch).');
            header('Location: /dashboard/financials/conciliation');
            exit;
        }

        // 3. Validate Size (Max 10MB)
        if ($fileSize > 10 * 1024 * 1024) {
            Flash::error('Arquivo muito grande. O limite é 10MB.');
            header('Location: /dashboard/financials/conciliation');
            exit;
        }

        // Create uploads dir if not exists
        $uploadDir = __DIR__ . '/../../storage/uploads';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);

        // Sanitize filename
        $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '', basename($fileName));
        $target = $uploadDir . '/' . uniqid() . '_' . $safeName;

        if (move_uploaded_file($file, $target)) {
            $result = $this->service->importReport($target);

            if (isset($result['error'])) {
                Flash::error($result['error']);
            } else {
                Flash::success("Importado com sucesso! {$result['imported']} registros. {$result['errors']} erros.");

                // Trigger reconciliation immediately
                $recon = $this->service->reconcile();
                Flash::info("Conciliação automática: {$recon['matched']} combinados, {$recon['mismatch']} divergentes.");
            }

            // Clean up
            if (file_exists($target)) {
                unlink($target);
            }
        } else {
            Flash::error('Falha ao mover arquivo.');
        }

        header('Location: /dashboard/financials/conciliation');
        exit;
    }

    public function reconcile()
    {
        try {
            $recon = $this->service->reconcile();
            Flash::success("Conciliação manual concluída: {$recon['matched']} combinados.");
            header('Location: /dashboard/financials/conciliation');
            exit;
        } catch (\Throwable $e) {
            log_error('Erro ao reconciliar settlements: ' . $e->getMessage());
            Flash::error('Erro ao reconciliar: ' . $e->getMessage());
            header('Location: /dashboard/financials/conciliation');
            exit;
        }
    }
}
