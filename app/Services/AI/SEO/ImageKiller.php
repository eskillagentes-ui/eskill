<?php

declare(strict_types=1);

namespace App\Services\AI\SEO;

use App\Database;
use App\Services\MercadoLivreClient;
use App\Services\CacheService;
use App\Services\AI\Core\AIProviderManager;
use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use GuzzleHttp\Client;
use PDO;

/**
 * 📸 IMAGE KILLER - Otimização de Imagens
 *
 * Analisa e otimiza imagens dos anúncios:
 * - Verificação de qualidade (Resolução, Aspect Ratio)
 * - Detecção de fundo via IA Vision
 * - Análise de conformidade com regras ML
 * - Sugestões de melhoria
 * - Cache de análises por 7 dias
 *
 * @author AI Development Team
 * @version 1.1.0
 */
class ImageKiller
{
    private PDO $db;
    private ?int $accountId;
    private ?MercadoLivreClient $mlClient = null;
    private ?AIProviderManager $aiProvider = null;
    private ?CacheService $cache = null;

    // ML recommendations
    private const MIN_RESOLUTION = 500;
    private const IDEAL_RESOLUTION = 1200;
    private const MAX_RESOLUTION = 10000; // ML limit
    private const CACHE_TTL = 604800; // 7 dias em segundos

    public function __construct(?int $accountId = null)
    {
        $this->db = Database::getInstance();
        $this->accountId = $accountId;

        if ($accountId) {
            $this->mlClient = new MercadoLivreClient($accountId);
        }

        $this->aiProvider = new AIProviderManager();
        $this->cache = new CacheService();
    }

    /**
     * 🚀 Otimizar imagens (Análise básica)
     */
    public function optimize(string $itemId): array
    {
        return $this->analyzeImages($itemId);
    }

    /**
     * 📸 Analisar imagens de um anúncio
     */
    public function analyzeImages(string $itemId): array
    {
        $result = [
            'item_id' => $itemId,
            'image_score' => 100,
            'total_images' => 0,
            'images' => [],
            'issues' => [],
            'recommendations' => [],
        ];

        if (!$this->mlClient) {
            return ['error' => 'ML client não disponível'];
        }

        try {
            $item = $this->mlClient->get("/items/{$itemId}");
            $pictures = $item['pictures'] ?? [];

            $result['total_images'] = count($pictures);

            // Check quantity
            if ($result['total_images'] < 4) {
                $result['issues'][] = 'Poucas imagens (recomendado: 5+)';
                $result['recommendations'][] = 'Adicione mais fotos mostrando detalhes e uso';
                $result['image_score'] -= 20;
            } elseif ($result['total_images'] < 6) {
                $result['image_score'] -= 10;
            }

            foreach ($pictures as $index => $pic) {
                $analysis = $this->analyzeSingleImage($pic, $index === 0);
                $result['images'][] = $analysis;

                if (!$analysis['is_compliant']) {
                    $result['image_score'] -= $analysis['penalty'];
                    foreach ($analysis['issues'] as $issue) {
                        $result['issues'][] = "Foto " . ($index + 1) . ": " . $issue;
                    }
                }
            }

            // Normalize score
            $result['image_score'] = max(0, $result['image_score']);

            if (!empty($result['images'][0])) {
                $aiAnalysis = $this->analyzeMainImageAI($result['images'][0]['url']);
                $result['ai_analysis'] = $aiAnalysis;
            }
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * 🖼️ Analisar uma única imagem (Metadados)
     */
    private function analyzeSingleImage(array $pic, bool $isMain): array
    {
        $analysis = [
            'id' => $pic['id'],
            'url' => $pic['url'],
            'width' => (int)($pic['size']['width'] ?? 0), // ML API sometimes gives size strings
            'height' => (int)($pic['size']['height'] ?? 0),
            'is_main' => $isMain,
            'is_compliant' => true,
            'issues' => [],
            'penalty' => 0,
        ];

        // Parse size if it's like "500x500"
        if (empty($analysis['width']) && isset($pic['size'])) {
            if (is_string($pic['size'])) {
                $parts = explode('x', strtolower($pic['size']));
                if (count($parts) === 2) {
                    $analysis['width'] = (int)$parts[0];
                    $analysis['height'] = (int)$parts[1];
                }
            }
        }

        // Resolution checks
        if ($analysis['width'] < self::MIN_RESOLUTION || $analysis['height'] < self::MIN_RESOLUTION) {
            $analysis['is_compliant'] = false;
            $analysis['issues'][] = "Baixa resolução (< " . self::MIN_RESOLUTION . "px)";
            $analysis['penalty'] += 15;
        } elseif ($analysis['width'] < self::IDEAL_RESOLUTION || $analysis['height'] < self::IDEAL_RESOLUTION) {
            // Warning, no heavy penalty
            $analysis['issues'][] = "Resolução abaixo do ideal (< " . self::IDEAL_RESOLUTION . "px)";
            $analysis['penalty'] += 5;
        }

        // Aspect Ratio (ML prefers 1:1, allows others but zooms/crops)
        if ($analysis['width'] > 0) {
            $ratio = $analysis['width'] / $analysis['height'];
            if ($ratio < 0.8 || $ratio > 1.2) {
                // Not square enough
                if ($isMain) {
                    $analysis['is_compliant'] = false;
                    $analysis['issues'][] = "Proporção inadequada (ideal: 1:1)";
                    $analysis['penalty'] += 10;
                }
            }
        }

        // Main image specific checks (Future: White background check)
        if ($isMain) {
            $imageResource = null;
            if (in_array($analysis['type'], ['image/jpeg', 'image/jpg'], true)) {
                $imageResource = @imagecreatefromjpeg($imageUrl);
            } elseif ($analysis['type'] === 'image/png') {
                $imageResource = @imagecreatefrompng($imageUrl);
            } elseif ($analysis['type'] === 'image/gif') {
                $imageResource = @imagecreatefromgif($imageUrl);
            }

            if ($imageResource) {
                $width = imagesx($imageResource);
                $height = imagesy($imageResource);
                $samples = [
                    [0, 0],
                    [$width - 1, 0],
                    [0, $height - 1],
                    [$width - 1, $height - 1],
                ];
                $brightness = 0;
                foreach ($samples as $point) {
                    $rgb = imagecolorat($imageResource, $point[0], $point[1]);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    $brightness += ($r + $g + $b) / 3;
                }
                imagedestroy($imageResource);
                $avgBrightness = $brightness / count($samples);
                if ($avgBrightness < 220) {
                    $analysis['is_compliant'] = false;
                    $analysis['issues'][] = 'Fundo não é branco no canto da imagem';
                    $analysis['penalty'] += 10;
                }
            }
        }

        return $analysis;
    }

    /**
     * 🤖 Análise IA da imagem principal (Visual)
     * Com cache de 7 dias e validação de URL
     */
    private function analyzeMainImageAI(string $imageUrl): array
    {
        if (!class_exists('Google\Cloud\Vision\V1\ImageAnnotatorClient')) {
            throw new \Exception("Google Cloud Vision library not found. Please run 'composer require google/cloud-vision'.");
        }

        $cacheKey = 'image_analysis_vision_' . md5($imageUrl);
        if ($this->cache && $this->cache->has($cacheKey)) {
            return $this->cache->get($cacheKey);
        }

        try {
            // Check if credentials are set
            if (empty($_ENV['GOOGLE_APPLICATION_CREDENTIALS'])) {
                throw new \Exception('Google Vision API credentials are not configured.');
            }

            $imageAnnotator = new ImageAnnotatorClient();

            // Perform the analysis
            $response = $imageAnnotator->annotateImage(
                file_get_contents($imageUrl),
                [
                    'LABEL_DETECTION',
                    'IMAGE_PROPERTIES',
                    'OBJECT_LOCALIZATION',
                    'TEXT_DETECTION'
                ]
            );

            // Process the results
            $result = $this->processVisionApiResponse($response);

            if ($this->cache) {
                $this->cache->set($cacheKey, $result, self::CACHE_TTL);
            }

            $imageAnnotator->close();
            return $result;
        } catch (\Exception $e) {
            log_warning('ImageKiller: falha na análise Google Vision', [
                'service' => 'ImageKiller',
                'error' => $e->getMessage(),
            ]);
            return [
                'error' => $e->getMessage(),
                'background_score' => 0,
                'clarity_score' => 0,
                'content_flags' => ['analysis_failed'],
                'suggestion' => 'Não foi possível analisar a imagem com a API de Visão.'
            ];
        }
    }

    /**
     * Processes the response from Google Vision API into our standard format.
     */
    private function processVisionApiResponse($response): array
    {
        $labels = $response->getLabelAnnotations();
        $properties = $response->getImagePropertiesAnnotation();
        $objects = $response->getLocalizedObjectAnnotations();
        $texts = $response->getTextAnnotations();

        $contentFlags = [];
        $suggestion = [];

        // Check for text, logos, watermarks from labels
        foreach ($labels as $label) {
            $desc = strtolower($label->getDescription());
            if (in_array($desc, ['logo', 'brand'])) {
                $contentFlags[] = 'logo';
            }
            if ($desc === 'watermark') {
                $contentFlags[] = 'watermark';
            }
            if ($desc === 'text') {
                $contentFlags[] = 'text';
            }
            if ($desc === 'border') {
                $contentFlags[] = 'border';
            }
        }

        // Also check for text via TextDetection
        if (count($texts) > 1) { // First one is the full block
            $contentFlags[] = 'text';
        }

        // Calculate background score
        $backgroundScore = 0;
        if ($properties) {
            $dominantColors = $properties->getDominantColors()->getColors();
            if (count($dominantColors) > 0) {
                $firstColor = $dominantColors[0];
                $color = $firstColor->getColor();
                $isWhite = ($color->getRed() > 240 && $color->getGreen() > 240 && $color->getBlue() > 240);

                if ($isWhite) {
                    // Score is high if the most dominant color is white and covers a large area
                    $backgroundScore = min(100, (int)($firstColor->getPixelFraction() * 120));
                } else {
                    $backgroundScore = 100 - (int)($firstColor->getPixelFraction() * 100);
                }
            }
        }
        if ($backgroundScore < 85) {
            $suggestion[] = 'Fundo não é branco puro.';
        }


        $clarityScore = 0;
        if ($properties) {
            $dominantColors = $properties->getDominantColors()->getColors();
            $total = 0;
            $mean = 0;
            foreach ($dominantColors as $colorInfo) {
                $color = $colorInfo->getColor();
                $weight = $colorInfo->getPixelFraction();
                $brightness = ($color->getRed() + $color->getGreen() + $color->getBlue()) / 3;
                $mean += $brightness * $weight;
                $total += $weight;
            }
            $variance = 0;
            if ($total > 0) {
                foreach ($dominantColors as $colorInfo) {
                    $color = $colorInfo->getColor();
                    $weight = $colorInfo->getPixelFraction();
                    $brightness = ($color->getRed() + $color->getGreen() + $color->getBlue()) / 3;
                    $variance += pow($brightness - $mean, 2) * $weight;
                }
                $variance = $variance / $total;
            }
            $clarityScore = min(100, max(0, round(sqrt($variance) * 2)));
        }
        if ($clarityScore < 60) {
            $suggestion[] = 'Imagem pode estar com baixa nitidez.';
        }

        // Finalize suggestions
        if (in_array('logo', $contentFlags) || in_array('watermark', $contentFlags)) {
            $suggestion[] = 'Remova logos ou marcas d\'água.';
        }
        if (in_array('text', $contentFlags)) {
            $suggestion[] = 'Evite texto na imagem.';
        }

        return [
            'background_score' => $backgroundScore,
            'clarity_score' => $clarityScore,
            'content_flags' => array_unique($contentFlags),
            'suggestion' => implode(' ', $suggestion)
        ];
    }




    /**
     * 📤 Upload de imagem para armazenamento temporário
     */
    public function uploadImage(string $itemId, array $file): array
    {
        // 1. Validate File — use server-side MIME detection, NOT client-supplied type
        $allowedMimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);
        if (!isset($allowedMimeToExt[$realMime])) {
            throw new \Exception('Tipo de arquivo inválido. Use JPG, PNG ou WEBP.');
        }

        if ($file['size'] > 10 * 1024 * 1024) { // 10MB limit
            throw new \Exception('Arquivo muito grande. Máximo 10MB.');
        }

        // 2. Sanitize itemId to prevent path traversal
        $safeItemId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $itemId);
        if ($safeItemId === '') {
            throw new \Exception('ID de item inválido.');
        }

        // 3. Prepare Directory — use storage/ (outside public web root)
        $uploadDir = __DIR__ . '/../../../../storage/uploads/seo_killer/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // 4. Move File — use safe extension from MIME map, never from user input
        $extension = $allowedMimeToExt[$realMime];
        $filename = $safeItemId . '_' . uniqid() . '.' . $extension;
        $targetPath = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new \Exception('Falha ao mover arquivo carregado.');
        }

        // 5. Return reference (file served via controller, not direct access)
        return [
            'id' => 'temp_' . uniqid(),
            'url' => '/api/seo-killer/uploads/' . $filename,
            'status' => 'uploaded',
            'file_path' => $targetPath // Internal use only
        ];
    }

    /**
     * 🔄 Atualizar imagens do item (Reorder, Add, Remove)
     */
    public function updateImages(string $itemId, array $changes): array
    {
        // $changes structure: details about additions, removals, or reordering
        // However, for ML API, we usually just send the full list of picture URLs in the desired order.

        if (!$this->mlClient) {
            throw new \Exception('ML Client not available');
        }

        // 1. Fetch current item to get existing pictures
        $item = $this->mlClient->get("/items/{$itemId}");
        $currentPictures = $item['pictures'] ?? [];

        // Map current pictures by ID for easy access
        $pictureMap = [];
        foreach ($currentPictures as $pic) {
            $pictureMap[$pic['id']] = $pic['url']; // Or source
        }

        // 2. Process Changes to build new picture list
        // We expect the frontend to send the FINAL ORDER of image IDs (existing) and Objects (new)
        // Or strictly a list of operations.
        // Let's assume the frontend sends the "final state" as an ordered list of IDs/Objects to simplify.
        // IF the input is truly a list of changes (Audit log style), we'd process them sequentially.
        // But for "Apply Changes", sending the desired state is often safer.

        // Let's inspect what the frontend logic in image-analyzer-modal.php does...
        // It sends { changes: [{type: 'upload', data: ...}, {type: 'reorder', data: [...]}, {type: 'remove', data: 'id'}] }

        $newPictureList = $currentPictures;

        foreach ($changes as $change) {
            switch ($change['type']) {
                case 'remove':
                    // Filter out the removed ID
                    $removeId = $change['data'];
                    $newPictureList = array_values(array_filter($newPictureList, function (array $p) use ($removeId): bool {
                        return $p['id'] !== $removeId;
                    }));
                    break;

                case 'reorder':
                    // Reorder based on ID list
                    $orderIds = $change['data']; // ['qtde123', 'temp_123', ...]
                    $orderedMap = [];
                    foreach ($newPictureList as $p) {
                        $orderedMap[$p['id']] = $p;
                    }

                    $reordered = [];
                    foreach ($orderIds as $id) {
                        if (isset($orderedMap[$id])) {
                            $reordered[] = $orderedMap[$id];
                            unset($orderedMap[$id]); // Remove to handle duplicates or legit moves
                        }
                    }
                    // Append any remaining (shouldn't happen if frontend is synced, but safety first)
                    foreach ($orderedMap as $p) {
                        $reordered[] = $p;
                    }
                    $newPictureList = $reordered;
                    break;

                case 'upload':
                    // Append new image (already uploaded to backend temp, need to push to ML?)
                    // ML API allows sending source: 'http://...' for new images.
                    // The temp URL must be publicly accessible for ML to fetch it.
                    // If local dev, this might fail without ngrok.
                    // Assuming production env/public IP.

                    // Frontend 'upload' change just gives the response from uploadImage endpoint
                    $uploadData = $change['data']; // {id: temp_..., url: ..., file_path: ...}

                    // We need the Absolute URL for ML
                    $baseUrl = $_ENV['APP_URL'] ?? 'https://eskill.com.br';

                    if (isset($uploadData['uploaded']) && is_array($uploadData['uploaded'])) {
                        // Handle multiple files
                        foreach ($uploadData['uploaded'] as $file) {
                            $publicUrl = $baseUrl . $file['url'];
                            $newPictureList[] = ['source' => $publicUrl];
                        }
                    } elseif (isset($uploadData['url'])) {
                        // Handle single file (legacy/fallback)
                        $publicUrl = $baseUrl . $uploadData['url'];
                        $newPictureList[] = ['source' => $publicUrl];
                    }
                    break;
            }
        }

        // 3. Prepare Payload for ML
        $picturesPayload = [];
        foreach ($newPictureList as $pic) {
            if (isset($pic['id']) && strpos($pic['id'], 'temp_') === false) {
                // Existing ML image
                $picturesPayload[] = ['id' => $pic['id']];
            } elseif (isset($pic['source'])) {
                // New image via URL
                $picturesPayload[] = ['source' => $pic['source']];
            } elseif (isset($pic['url'])) {
                // Fallback if structure varies
                $picturesPayload[] = ['source' => $pic['url']];
            }
        }

        // 4. Send Update to ML
        // Governança: bloqueia escrita em conta blacklist (FORBIDDEN_ACCOUNTS, ex. 1335).
        (new \App\Services\HiddenSeo\SafetyGuard())->assertCanApply((int)$this->accountId, false, true);

        $response = $this->mlClient->put("/items/{$itemId}", [
            'pictures' => $picturesPayload
        ]);

        // 5. Clean up temp files if successful?
        // Better to leave them for a cron job or delete immediately if confirmed.

        return $response;
    }

    /**
     * 🧹 Remover fundo (Integração futura)
     */
    public function removeBackground(string $imageUrl): string
    {
        $apiKey = $_ENV['REMOVE_BG_API_KEY'] ?? null;
        if (!$apiKey) {
            throw new \Exception('REMOVE_BG_API_KEY não configurada.');
        }

        $client = new Client(['base_uri' => 'https://api.remove.bg', 'timeout' => 60.0]);
        $response = $client->post('/v1.0/removebg', [
            'headers' => [
                'X-Api-Key' => $apiKey,
            ],
            'form_params' => [
                'image_url' => $imageUrl,
                'size' => 'auto'
            ]
        ]);

        $body = $response->getBody()->getContents();
        return 'data:image/png;base64,' . base64_encode($body);
    }
}
