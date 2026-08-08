<?php

declare(strict_types=1);

namespace App\ValueObjects\HiddenSeo;

/**
 * Evidência auditável de uma sugestão Hidden SEO.
 */
final class Evidence
{
    private string $source;
    private string $provenance;
    private int $confidence;

    public function __construct(string $source, string $provenance, int $confidence)
    {
        if ($confidence < 0 || $confidence > 100) {
            throw new \InvalidArgumentException('confidence deve estar entre 0 e 100');
        }
        if (trim($source) === '') {
            throw new \InvalidArgumentException('source de evidência obrigatória');
        }
        $this->source = trim($source);
        $this->provenance = trim($provenance);
        $this->confidence = $confidence;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function provenance(): string
    {
        return $this->provenance;
    }

    public function confidence(): int
    {
        return $this->confidence;
    }

    public function isHighConfidence(): bool
    {
        return $this->confidence >= 85;
    }

    public function isMediumConfidence(): bool
    {
        return $this->confidence >= 60 && $this->confidence < 85;
    }

    /**
     * @return array{source:string,provenance:string,confidence:int}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'provenance' => $this->provenance,
            'confidence' => $this->confidence,
        ];
    }
}
