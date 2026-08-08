<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Legado: antes gravava em `webhook_events` sem idempotência.
 *
 * O caminho canônico é MercadoLivreWebhookController (inbox + jobs).
 * Mantido como delegação para não perder eventos se alguma rota antiga
 * ainda apontar para esta classe.
 */
class WebhookController
{
    /**
     * Recebe notificações do Mercado Livre (delega ao fluxo idempotente).
     */
    public function receive(): void
    {
        (new MercadoLivreWebhookController())->receive();
    }
}
