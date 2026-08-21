<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\EventBus;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Validator;

abstract class BaseController
{
    protected ?Container $container = null;
    protected Request $request;

    public function __construct()
    {
        $this->request = new Request();
    }

    public function setContainer(Container $container): void
    {
        $this->container = $container;
    }

    protected function get(string $key)
    {
        if ($this->container) {
            return $this->container->get($key);
        }
        throw new \RuntimeException("Container not set in controller");
    }

    /**
     * Helper para JSON response
     */
    protected function json(array $data, int $status = 200): void
    {
        if ($this->canSendHeaders()) {
            http_response_code($status);
            header('Content-Type: application/json');
        }
        echo json_encode($data);
        // Fora de CLI (php-fpm/apache/servidor embutido) sempre finaliza a
        // resposta aqui, como antes. Em CLI (scripts, workers, e os testes
        // PHPUnit que chamam métodos de controller diretamente) exit; mataria
        // o processo do runner antes de coletar o resultado do teste — não há
        // uma requisição HTTP real para finalizar de qualquer forma.
        if (PHP_SAPI !== 'cli') {
            exit;
        }
    }

    /**
     * Verifica se ainda é seguro enviar headers HTTP: fora de CLI e antes de
     * qualquer output ter sido enviado. Mesmo padrão já usado em Router e em
     * outros controllers (ex.: HealthController, SEOToolsController).
     */
    protected function canSendHeaders(): bool
    {
        return PHP_SAPI !== 'cli' && !headers_sent();
    }

    /**
     * Helper para JSON success response
     */
    protected function jsonSuccess(array $data = [], string $message = ''): void
    {
        $response = ['success' => true];
        if ($message) {
            $response['message'] = $message;
        }
        $this->json(array_merge($response, $data));
    }

    /**
     * Helper para JSON error response
     */
    protected function jsonError(string $message, int $status = 500, array $extra = []): void
    {
        $this->json(array_merge(['success' => false, 'error' => $message], $extra), $status);
    }

    /**
     * Obtém account_id da sessão (tipado como int ou null)
     */
    protected function getAccountId(): ?int
    {
        return isset($_SESSION['account_id']) ? (int) $_SESSION['account_id'] : null;
    }

    /**
     * Obtém account_id com fallback para active_ml_account_id
     */
    protected function getActiveAccountId(): ?int
    {
        // 1) Sessão (fluxo web) — única chave: SessionHelper::getActiveAccountId()
        $fromSession = \App\Helpers\SessionHelper::getActiveAccountId();
        if ($fromSession !== null && $fromSession > 0) {
            return $fromSession;
        }

        // 2) Header (fluxo API/CLI): X-ML-Account-Id
        $header = $this->request->header('X-ML-Account-Id');
        if (is_string($header) && $header !== '') {
            $candidate = (int)$header;
            if ($candidate > 0) {
                return $candidate;
            }
        }

        // 3) Query/Body/JSON: ml_account_id (fallback compat: account_id)
        $candidate = $this->request->inputInt('ml_account_id', 0);
        if ($candidate > 0) {
            return $candidate;
        }

        $candidate = $this->request->inputInt('account_id', 0);
        if ($candidate > 0) {
            return $candidate;
        }

        return null;
    }

    /**
     * Obtém user_id da sessão
     */
    protected function getUserId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    /**
     * Obtém user_role da sessão
     */
    protected function getUserRole(): string
    {
        return (string) ($_SESSION['user_role'] ?? 'user');
    }

    /**
     * Verifica se o usuário é admin
     */
    protected function isAdmin(): bool
    {
        return !empty($_SESSION['is_admin']) || ($this->getUserRole() === 'admin');
    }

    /**
     * Obtém valor da sessão
     */
    protected function session(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Exige account_id ou retorna erro 401
     */
    protected function requireAccountId(): int
    {
        $id = $this->getAccountId();
        if (!$id) {
            $this->jsonError('Não autorizado', 401);
        }
        return $id;
    }

    /**
     * Exige user_id ou retorna erro 401
     */
    protected function requireUserId(): int
    {
        $id = $this->getUserId();
        if (!$id) {
            $this->jsonError('Autenticação necessária', 401);
        }
        return $id;
    }

    /**
     * Executa a lógica do controller dentro de try/catch padronizado
     * com logging automático de erros e respostas consistentes.
     *
     * Uso:
     *   $this->withErrorHandling(function() {
     *       $data = $this->service->getData();
     *       $this->jsonSuccess(['items' => $data]);
     *   }, 'MyController::myMethod');
     */
    protected function withErrorHandling(callable $callback, string $context = ''): void
    {
        try {
            $callback();
        } catch (\PDOException $e) {
            $this->logError($e, $context);
            $this->jsonError('Erro de banco de dados', 500);
        } catch (\InvalidArgumentException $e) {
            $this->logError($e, $context);
            $this->jsonError($e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->logError($e, $context);
            $isProduction = ($_ENV['APP_ENV'] ?? 'production') === 'production';
            $msg = $isProduction ? 'Erro interno do servidor' : 'Erro interno: ' . $e->getMessage();
            $this->jsonError($msg, 500);
        }
    }

    /**
     * Log de erro consistente com contexto
     */
    protected function logError(\Throwable $e, string $context = ''): void
    {
        log_error($e->getMessage(), [
            'context' => $context ?: get_class($this),
            'exception_class' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    }

    /**
     * Cria um Paginator a partir dos parâmetros da requisição atual.
     *
     * Uso:
     *   $p = $this->paginate(total: $total);
     *   $rows = $model->list($p->limit(), $p->offset());
     *   $this->jsonSuccess(['items' => $rows, 'pagination' => $p->meta()]);
     *
     * @param int $total          Total de registros (pode ser 0 e definido depois via setTotal)
     * @param int $defaultPerPage Valor padrão para per_page (default: 20)
     * @param int $maxPerPage     Limite máximo de per_page (default: 100)
     */
    protected function paginate(int $total = 0, int $defaultPerPage = 20, int $maxPerPage = 100): Paginator
    {
        return Paginator::fromRequest($this->request, $total, $defaultPerPage, $maxPerPage);
    }

    /**
     * Despacha um evento para o EventBus (quando disponível no container).
     *
     * @param string $event
     * @param array<string, mixed> $payload
     */
    protected function event(string $event, array $payload = []): void
    {
        if ($this->container && $this->container->has(EventBus::class)) {
            $this->container->get(EventBus::class)->dispatch($event, $payload);
        }
    }

    /**
     * Valida os inputs da requisição contra as regras fornecidas.
     * Retorna 422 com errors JSON caso a validação falhe.
     * Em caso de sucesso, retorna o array de campos validados (subset das rules).
     *
     * Exemplo:
     *   $data = $this->validateFields([
     *       'email' => 'required|email',
     *       'age'   => 'required|integer|min:18',
     *   ]);
     *
     * @param array<string, string|array<string>> $rules
     * @return array<string, mixed>
     */
    protected function validateFields(array $rules): array
    {
        $v = Validator::make($this->request->all(), $rules);
        if ($v->fails()) {
            $this->json(['success' => false, 'error' => 'Dados inválidos', 'errors' => $v->errors()], 422);
        }
        return $v->validated();
    }

    /**
     * Renderiza uma view com layout padrão
     *
     * @param string $viewPath  Caminho relativo à pasta Views (ex: 'dashboard/quality')
     * @param array  $data      Variáveis disponíveis na view via extract()
     * @param string|null $layout  Layout a usar (null = layout padrão)
     */
    protected function renderView(string $viewPath, array $data = [], ?string $layout = 'layouts/modern/app'): void
    {
        \App\Helpers\ViewHelper::render($viewPath, $data, $layout);
    }
}
