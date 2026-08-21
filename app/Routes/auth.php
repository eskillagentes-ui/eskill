<?php

declare(strict_types=1);

use App\Controllers\AuthController;

/** @var \App\Router $router */

// Alias para login (rotas curtas)
$router->get('login', AuthController::class, 'login');
$router->post('login', AuthController::class, 'doLogin');
$router->get('logout', AuthController::class, 'logout');
$router->post('logout', AuthController::class, 'logout');
$router->get('register', AuthController::class, 'register');
$router->post('register', AuthController::class, 'doRegister');

// Rotas de autenticação de usuários (padrão completo)
$router->get('auth/login', AuthController::class, 'login');
$router->post('auth/login', AuthController::class, 'doLogin');
$router->get('auth/register', AuthController::class, 'register');
$router->post('auth/register', AuthController::class, 'doRegister');
$router->get('auth/logout', AuthController::class, 'logout');
$router->post('auth/logout', AuthController::class, 'logout');
$router->get('auth/forgot-password', AuthController::class, 'forgotPassword');
$router->post('auth/forgot-password', AuthController::class, 'doForgotPassword');
$router->get('auth/reset-password', AuthController::class, 'resetPassword');
$router->post('auth/reset-password', AuthController::class, 'doResetPassword');
$router->get('auth/verify-email', AuthController::class, 'verifyEmail');

// Rotas de 2FA
$router->get('auth/2fa/verify', AuthController::class, 'verifyTwoFactor');
$router->post('auth/2fa/verify', AuthController::class, 'doVerifyTwoFactor');
$router->get('auth/2fa/setup', AuthController::class, 'setupTwoFactor');
$router->post('auth/2fa/setup', AuthController::class, 'doSetupTwoFactor');

// Rotas de autenticação Mercado Livre
$router->get('auth/authorize', AuthController::class, 'authorize');
$router->get('auth/callback', AuthController::class, 'callback');
$router->post('auth/callback', AuthController::class, 'callback');
$router->get('api/auth/accounts', AuthController::class, 'accounts');
$router->get('api/auth/oauth-config-status', AuthController::class, 'oauthConfigStatus');
$router->post('auth/disconnect/{accountId}', AuthController::class, 'disconnect');
$router->delete('auth/account/{accountId}', AuthController::class, 'deleteAccount');
$router->get('auth/mobile/status', AuthController::class, 'status');
$router->post('auth/mobile/login', AuthController::class, 'mobileLogin');

// Rotas de sincronização de contas
$router->post('api/accounts/{accountId}/sync', AuthController::class, 'syncAccount');
$router->get('api/accounts/{accountId}/sync/status', AuthController::class, 'getSyncStatus');
$router->post('api/accounts/sync-all', AuthController::class, 'syncAllAccounts');
