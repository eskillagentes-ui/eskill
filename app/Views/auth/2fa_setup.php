<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurar 2FA - Mercado Livre Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body {
            background: #f8f9fa;
            min-height: 100vh;
        }

        .setup-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-top: 2rem;
        }
    </style>
</head>

<body>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="setup-card">
                    <div class="text-center mb-4">
                        <i class="bi bi-shield-check text-primary" style="font-size: 3rem;"></i>
                        <h2 class="mt-3">Configurar Autenticação de Dois Fatores</h2>
                        <p class="text-muted">Aumente a segurança da sua conta</p>
                    </div>

                    <?php


if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($_SESSION['error']) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['error']); ?>
                    <?php endif; ?>

                    <div class="row mb-4">
                        <div class="col-12 text-center">
                            <p>1. Escaneie o QR Code abaixo com seu aplicativo autenticador (Google Authenticator, Authy, etc.)</p>
                            <img src="<?= htmlspecialchars($qrCodeUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="QR Code 2FA" class="img-fluid border p-2 rounded mb-3">
                            <p class="small text-muted">Ou digite o código manualmente: <strong><?= htmlspecialchars($secret, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>
                        </div>
                    </div>

                    <form action="/auth/2fa/setup" method="POST">
                        <input type="hidden" name="_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                        <input type="hidden" name="secret" value="<?= htmlspecialchars($secret, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

                        <div class="mb-3">
                            <label for="code" class="form-label">2. Digite o código gerado pelo aplicativo</label>
                            <input type="text" class="form-control text-center fs-4" id="code" name="code" required autocomplete="off" placeholder="000000" maxlength="6">
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">Ativar 2FA</button>
                            <a href="/profile" class="btn btn-outline-secondary">Cancelar</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>