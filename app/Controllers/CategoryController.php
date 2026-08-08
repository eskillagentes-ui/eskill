<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Services\CategoryService;
use App\Helpers\SessionHelper;
use App\Services\UserService;

class CategoryController
{
    private CategoryService $categoryService;
    private UserService $userService;
    private Request $request;

    public function __construct()
    {
        $this->request = new Request();
        $this->userService = new UserService();
        // Strict Auth Check for API
        if (!$this->userService->isAuthenticated()) {
             http_response_code(401);
             header('Content-Type: application/json');
             echo json_encode(['error' => 'Unauthorized']);
             exit;
        }

        $accountId = SessionHelper::getActiveAccountId();
        $this->categoryService = new CategoryService($accountId);
    }

    /**
     * Lista todas as categorias
     * Envelope estável: { success, categories: [{id,name}, ...] }
     * (consumido por Oportunidades e SEO Killer — nunca ecoar erro cru do ML).
     */
    public function index(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');

        try {
            $categories = $this->categoryService->getAllCategories();
        } catch (\Throwable $e) {
            log_warning('CategoryController::index falhou', ['error' => $e->getMessage()]);
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'categories' => [],
                'error' => 'categories_unavailable',
                'message' => 'Não foi possível carregar categorias',
            ]);
            return;
        }

        if (!CategoryService::isCategoryList($categories)) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'categories' => [],
                'error' => 'categories_unavailable',
                'message' => 'Nenhuma categoria disponível',
            ]);
            return;
        }

        echo json_encode([
            'success' => true,
            'categories' => array_values($categories),
        ]);
    }

    /**
     * Obtém detalhes de uma categoria
     */
    public function show(string $categoryId): void
    {
        $category = $this->categoryService->getCategory($categoryId);

        header('Content-Type: application/json');
        echo json_encode($category);
    }

    /**
     * Busca categoria por nome
     */
    public function search(): void
    {
        $term = $this->request->get('q', '') ?? '';

        if (empty($term)) {
            http_response_code(400);
            echo json_encode(['error' => 'Parâmetro "q" é obrigatório']);
            return;
        }

        $results = $this->categoryService->searchCategoryByName($term);

        header('Content-Type: application/json');
        echo json_encode($results);
    }

    /**
     * Obtém marcas de uma categoria
     */
    public function brands(string $categoryId): void
    {
        $brands = $this->categoryService->getBrandsForCategory($categoryId);

        header('Content-Type: application/json');
        echo json_encode($brands);
    }

    /**
     * Obtém subcategorias
     */
    public function subcategories(string $categoryId): void
    {
        $subcategories = $this->categoryService->getSubcategories($categoryId);

        header('Content-Type: application/json');
        echo json_encode($subcategories);
    }

    /**
     * Obtém árvore de categorias
     */
    public function tree(): void
    {
        header('Content-Type: application/json');

        $tree = $this->categoryService->getCategoryTree();

        // Check if there's an error (e.g., no valid token)
        if (isset($tree['error'])) {
            http_response_code(isset($tree['status']) ? $tree['status'] : 500);
            echo json_encode([
                'error' => $tree['error'],
                'message' => 'Erro ao carregar categorias: ' . ($tree['error'] ?? 'desconhecido')
            ]);
            return;
        }

        // Ensure we return an array
        if (!is_array($tree)) {
            http_response_code(500);
            echo json_encode([
                'error' => 'invalid_response',
                'message' => 'Resposta inválida do serviço de categorias'
            ]);
            return;
        }

        echo json_encode($tree);
    }

    /**
     * Obtém atributos de uma categoria
     */
    public function attributes(string $categoryId): void
    {
        $attributes = $this->categoryService->getCategoryAttributes($categoryId);

        header('Content-Type: application/json');
        echo json_encode($attributes);
    }

    /**
     * Obtém atributos que podem ser usados como filtros
     */
    public function filterableAttributes(string $categoryId): void
    {
        $attributes = $this->categoryService->getFilterableAttributes($categoryId);

        header('Content-Type: application/json');
        echo json_encode($attributes);
    }

    /**
     * Obtém atributos obrigatórios de uma categoria
     */
    public function requiredAttributes(string $categoryId): void
    {
        $attributes = $this->categoryService->getRequiredAttributes($categoryId);

        header('Content-Type: application/json');
        echo json_encode($attributes);
    }

    /**
     * Valida atributos fornecidos para uma categoria
     */
    public function validateAttributes(string $categoryId): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['attributes']) || !is_array($data['attributes'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Campo "attributes" é obrigatório e deve ser um array']);
            return;
        }

        $result = $this->categoryService->validateRequiredAttributes($categoryId, $data['attributes']);

        header('Content-Type: application/json');
        echo json_encode($result);
    }

    /**
     * Obtém valores possíveis para um atributo específico
     */
    public function attributeValues(string $categoryId, string $attributeId): void
    {
        $values = $this->categoryService->getAttributeValues($categoryId, urldecode($attributeId));

        header('Content-Type: application/json');
        echo json_encode($values);
    }
}
