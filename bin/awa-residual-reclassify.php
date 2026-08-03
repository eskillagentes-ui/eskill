#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Reclassifica itens AWA existentes com AwaResidualBrandScanner
 * (usa short_description do catálogo quando catalog_product_id estiver na evidência).
 */

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../app/Services/_runtime/bootstrap.php';

use App\Database;
use App\Services\AwaResidualBrandScanner;
use App\Services\MercadoLivreClient;

$opts = getopt('', ['account:', 'limit:', 'verbose', 'help']);
if (isset($opts['help'])) {
    echo "Uso: php bin/awa-residual-reclassify.php --account=1335 [--limit=200] [--verbose]\n";
    exit(0);
}

$accountId = isset($opts['account']) ? (int) $opts['account'] : 1335;
$limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 200;
$verbose = isset($opts['verbose']);

if ($accountId <= 0) {
    fwrite(STDERR, "account inválido\n");
    exit(2);
}

$pdo = Database::getInstance();
$client = new MercadoLivreClient($accountId);
$scanner = new AwaResidualBrandScanner();

$stmt = $pdo->prepare(
    'SELECT id, ml_item_id, title, brand_match_type, evidence_json
       FROM awa_seller_items
      WHERE account_id = :aid
      ORDER BY id DESC
      LIMIT :lim'
);
$stmt->bindValue('aid', $accountId, PDO::PARAM_INT);
$stmt->bindValue('lim', $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
$rejected = 0;
$unchanged = 0;

$updateStmt = $pdo->prepare(
    'UPDATE awa_seller_items
        SET brand_match_type = :match_type,
            has_brand_attribute = :has_brand,
            evidence_json = :evidence,
            updated_at = NOW()
      WHERE id = :id AND account_id = :aid'
);

foreach ($rows as $row) {
    $evidence = json_decode((string) ($row['evidence_json'] ?? '{}'), true);
    if (!is_array($evidence)) {
        $evidence = [];
    }

    $catalogProductId = (string) ($evidence['catalog_product_id'] ?? '');
    $shortDescription = '';
    $attributesText = '';
    $brandValue = '';
    $brandValueId = '';
    $domainId = '';
    $listingDescription = (string) ($evidence['description'] ?? '');

    if ($catalogProductId !== '') {
        $product = $client->get('/products/' . $catalogProductId, [], 20, false);
        if (!isset($product['error']) && isset($product['id'])) {
            $domainId = (string) ($product['domain_id'] ?? '');
            if (is_array($product['short_description'] ?? null)) {
                $shortDescription = (string) ($product['short_description']['content'] ?? '');
            }
            foreach ($product['attributes'] ?? [] as $attribute) {
                if (!is_array($attribute)) {
                    continue;
                }
                $attributesText .= ' ' . (string) ($attribute['id'] ?? '') . '=' . (string) ($attribute['value_name'] ?? '');
                if (($attribute['id'] ?? '') === 'BRAND') {
                    $brandValue = (string) ($attribute['value_name'] ?? '');
                    $brandValueId = (string) ($attribute['value_id'] ?? '');
                }
            }
        }
        usleep(80000);
    }

    // Descrição do anúncio via CF proxy (terceiros liberam /description com OAuth).
    $itemId = (string) ($row['ml_item_id'] ?? '');
    if ($itemId !== '' && ($listingDescription === '' || strlen($listingDescription) < 40)) {
        $descResp = $client->get('/items/' . $itemId . '/description', [], 20, true);
        $httpStatus = isset($descResp['status']) ? (int) $descResp['status'] : 0;
        $ok = !isset($descResp['error']) && ($httpStatus === 0 || $httpStatus < 400);
        if ($ok) {
            $plain = trim((string) ($descResp['plain_text'] ?? $descResp['text'] ?? ''));
            if ($plain !== '') {
                $listingDescription = $plain;
                $evidence['description'] = $listingDescription;
                $evidence['description_fetched_at'] = date('c');
            }
        }
        usleep(100000);
    }

    $classification = $scanner->classify([
        'title' => (string) ($row['title'] ?? ''),
        'catalog_name' => (string) ($row['title'] ?? ''),
        'short_description' => $shortDescription,
        'description' => $listingDescription,
        'attributes_text' => trim($attributesText),
        'brand_value' => $brandValue,
        'brand_value_id' => $brandValueId,
        'domain_id' => $domainId,
    ]);

    $oldType = (string) ($row['brand_match_type'] ?? 'unclassified');
    $newType = $classification['match_type'];
    $confidence = (int) $classification['confidence'];

    // Sem sinais suficientes, não rebaixa matches já confirmados.
    if ($newType === 'unclassified' && in_array($oldType, ['attribute_match', 'catalog_winner', 'title_match', 'title_match_only'], true)) {
        $newType = $oldType;
        $oldConf = (int) (($evidence['brand_analysis']['confidence'] ?? 0));
        $confidence = $oldConf > 0 ? $oldConf : $confidence;
    }

    if ($classification['rejected_noise']) {
        $del = $pdo->prepare('DELETE FROM awa_seller_items WHERE id = :id AND account_id = :aid');
        $del->execute(['id' => (int) $row['id'], 'aid' => $accountId]);
        $rejected++;
        if ($verbose) {
            echo "REJECT {$row['ml_item_id']} ({$oldType})\n";
        }
        continue;
    }

    $evidence['brand_analysis'] = array_merge(
        is_array($evidence['brand_analysis'] ?? null) ? $evidence['brand_analysis'] : [],
        [
            'match_type' => $newType,
            'confidence' => $confidence,
            'residual_hits' => $classification['residual_hits'],
            'reasons' => $classification['reasons'],
            'source' => 'awa_residual_reclassify',
            'description_awa_hits' => count(array_filter(
                $classification['residual_hits'],
                static fn (array $hit): bool => ($hit['field'] ?? '') === 'description'
            )),
        ]
    );

    $updateStmt->execute([
        'match_type' => $newType,
        'has_brand' => $classification['has_awa_brand_attribute'] ? 1 : 0,
        'evidence' => json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'id' => (int) $row['id'],
        'aid' => $accountId,
    ]);
    $updated++;

    if ($verbose) {
        $descHits = (int) ($evidence['brand_analysis']['description_awa_hits'] ?? 0);
        echo "UPDATE {$row['ml_item_id']} {$oldType} -> {$newType} conf={$confidence} desc_hits={$descHits} desc_len=" . strlen($listingDescription) . "\n";
    }
}

$pdo->exec(
    'UPDATE awa_seller_registry r
        SET items_count = (
              SELECT COUNT(*) FROM awa_seller_items i WHERE i.seller_registry_id = r.id
            ),
            updated_at = NOW()
      WHERE r.account_id = ' . (int) $accountId
);

echo json_encode([
    'account_id' => $accountId,
    'scanned' => count($rows),
    'updated' => $updated,
    'rejected_noise' => $rejected,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
