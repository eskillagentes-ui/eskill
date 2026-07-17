<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Security\AccountAccessException;
use App\Security\AccountContextResolver;
use App\Services\SEO\CompatibilityService;

class CompatibilityController extends BaseController
{
    private CompatibilityService $compatibilityService;
    
    public function __construct()
    {
        parent::__construct();
        // SEC-001: não confiar em account_id cru
        $accountId = null;
        try {
            $accountId = (new AccountContextResolver())
                ->authorizeForCurrentActor('compatibility.read')
                ->accountId();
        } catch (AccountAccessException $e) {
            $accountId = null;
        }
        $this->compatibilityService = new CompatibilityService($accountId);
    }
    
    /**
     * Busca produtos por compatibilidade
     * GET /api/compatibility/search
     */
    public function search(): void
    {
        $categoryId = $this->request->get('category');
        
        if (!$categoryId) {
            http_response_code(400);
            echo json_encode(['error' => 'Parâmetro "category" é obrigatório']);
            return;
        }
        
        // Coletar dados de compatibilidade
        $compatibilityData = [];
        
        $brand = $this->request->get('BRAND');
        if ($brand !== null) {
            $compatibilityData['BRAND'] = $brand;
        }
        $model = $this->request->get('MODEL');
        if ($model !== null) {
            $compatibilityData['MODEL'] = $model;
        }
        $year = $this->request->get('YEAR');
        if ($year !== null) {
            $compatibilityData['YEAR'] = $year;
        }
        $vehicleBrand = $this->request->get('VEHICLE_BRAND');
        if ($vehicleBrand !== null) {
            $compatibilityData['VEHICLE_BRAND'] = $vehicleBrand;
        }
        $vehicleModel = $this->request->get('VEHICLE_MODEL');
        if ($vehicleModel !== null) {
            $compatibilityData['VEHICLE_MODEL'] = $vehicleModel;
        }
        $vehicleYear = $this->request->get('VEHICLE_YEAR');
        if ($vehicleYear !== null) {
            $compatibilityData['VEHICLE_YEAR'] = $vehicleYear;
        }
        
        $results = $this->compatibilityService->searchByCompatibility($categoryId, $compatibilityData);
        
        header('Content-Type: application/json');
        echo json_encode($results);
    }
    
    /**
     * Valida compatibilidade de um produto
     * POST /api/compatibility/validate/{itemId}
     */
    public function validate(string $itemId): void
    {
        $data = $this->request->json();
        
        if (!$data || !isset($data['vehicle_data'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Campo "vehicle_data" é obrigatório']);
            return;
        }
        
        $result = $this->compatibilityService->validateCompatibility($itemId, $data['vehicle_data']);
        
        header('Content-Type: application/json');
        echo json_encode($result);
    }
    
    /**
     * Sugere produtos compatíveis
     * GET /api/compatibility/suggest
     */
    public function suggest(): void
    {
        $categoryId = $this->request->get('category');
        
        if (!$categoryId) {
            http_response_code(400);
            echo json_encode(['error' => 'Parâmetro "category" é obrigatório']);
            return;
        }
        
        $vehicleData = [];
        
        $brand = $this->request->get('BRAND');
        if ($brand !== null) {
            $vehicleData['BRAND'] = $brand;
        }
        $model = $this->request->get('MODEL');
        if ($model !== null) {
            $vehicleData['MODEL'] = $model;
        }
        $year = $this->request->get('YEAR');
        if ($year !== null) {
            $vehicleData['YEAR'] = $year;
        }
        $vehicleBrand = $this->request->get('VEHICLE_BRAND');
        if ($vehicleBrand !== null) {
            $vehicleData['VEHICLE_BRAND'] = $vehicleBrand;
        }
        $vehicleModel = $this->request->get('VEHICLE_MODEL');
        if ($vehicleModel !== null) {
            $vehicleData['VEHICLE_MODEL'] = $vehicleModel;
        }
        $vehicleYear = $this->request->get('VEHICLE_YEAR');
        if ($vehicleYear !== null) {
            $vehicleData['VEHICLE_YEAR'] = $vehicleYear;
        }
        
        $limit = $this->request->getInt('limit', 20);
        
        $results = $this->compatibilityService->suggestCompatibleProducts($categoryId, $vehicleData, $limit);
        
        header('Content-Type: application/json');
        echo json_encode($results);
    }
    
    /**
     * Obtém atributos de compatibilidade de uma categoria
     * GET /api/compatibility/attributes/{categoryId}
     */
    public function attributes(string $categoryId): void
    {
        $attributes = $this->compatibilityService->getCompatibilityAttributes($categoryId);
        
        header('Content-Type: application/json');
        echo json_encode($attributes);
    }
}
