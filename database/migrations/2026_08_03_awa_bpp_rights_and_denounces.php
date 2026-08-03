<?php

declare(strict_types=1);

/**
 * Migration: direitos BPP cadastrados + log de denúncias AWA.
 *
 * php database/migrations/2026_08_03_awa_bpp_rights_and_denounces.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

$pdo = App\Database::getInstance();

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS awa_bpp_registered_rights (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id INT UNSIGNED NOT NULL,
        right_name VARCHAR(255) NOT NULL,
        country VARCHAR(64) NOT NULL DEFAULT \'Brasil\',
        site_id VARCHAR(8) NOT NULL DEFAULT \'MLB\',
        right_type VARCHAR(64) NOT NULL DEFAULT \'Marca\',
        subtype VARCHAR(64) NULL,
        registration_number VARCHAR(64) NOT NULL,
        classes VARCHAR(64) NULL,
        limitation TEXT NULL,
        status VARCHAR(32) NOT NULL DEFAULT \'active\',
        valid_until DATE NULL,
        portal_url VARCHAR(512) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_awa_bpp_right_reg (account_id, registration_number),
        KEY idx_awa_bpp_rights_account (account_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS awa_bpp_denounces (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id INT UNSIGNED NOT NULL,
        item_id VARCHAR(32) NOT NULL,
        seller_registry_id INT UNSIGNED NULL,
        report_reason_id VARCHAR(32) NOT NULL,
        comment TEXT NULL,
        denounce_id BIGINT NULL,
        api_status VARCHAR(64) NULL,
        http_status INT NULL,
        dry_run TINYINT(1) NOT NULL DEFAULT 0,
        response_json JSON NULL,
        created_by VARCHAR(100) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_awa_bpp_denounces_account (account_id, created_at),
        KEY idx_awa_bpp_denounces_item (item_id),
        KEY idx_awa_bpp_denounces_case (denounce_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$stmt = $pdo->prepare(
    'INSERT INTO awa_bpp_registered_rights
        (account_id, right_name, country, site_id, right_type, subtype, registration_number,
         classes, limitation, status, valid_until, portal_url, notes)
     VALUES
        (:account_id, :right_name, :country, :site_id, :right_type, :subtype, :registration_number,
         :classes, :limitation, :status, :valid_until, :portal_url, :notes)
     ON DUPLICATE KEY UPDATE
        right_name = VALUES(right_name),
        country = VALUES(country),
        site_id = VALUES(site_id),
        right_type = VALUES(right_type),
        subtype = VALUES(subtype),
        classes = VALUES(classes),
        limitation = VALUES(limitation),
        status = VALUES(status),
        valid_until = VALUES(valid_until),
        portal_url = VALUES(portal_url),
        notes = VALUES(notes)'
);

$stmt->execute([
    'account_id' => 1335,
    'right_name' => 'AWA MOTO COMPONENTES',
    'country' => 'Brasil',
    'site_id' => 'MLB',
    'right_type' => 'Marca',
    'subtype' => 'Mista',
    'registration_number' => '900058269',
    'classes' => '12',
    'limitation' => 'ESPELHOS RETROVISORES; ESTRIBOS DE VEÍCULOS / REARVIEW MIRRORS; VEHICLE STIRRUPS.',
    'status' => 'active',
    'valid_until' => '2029-08-25',
    'portal_url' => 'https://www.mercadolivre.com.br/brandprotection/enforcement',
    'notes' => 'Confirmado no portal BPP em 2026-08-03 (Direitos cadastrados → Ativo). API OAuth FACILYTY ainda retorna 403 User not Authorized em /moderations/pppi/denounces/MLB/ITM/options.',
]);

echo "OK: awa_bpp_registered_rights + awa_bpp_denounces + seed 900058269\n";
