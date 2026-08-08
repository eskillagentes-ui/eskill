<?php

declare(strict_types=1);

namespace App\ValueObjects\HiddenSeo;

/**
 * LINE — linha de produto curta (não título SEO).
 */
final class Line
{
    private string $value;
    private Evidence $evidence;

    public function __construct(string $value, Evidence $evidence)
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($value === '') {
            throw new \InvalidArgumentException('LINE não pode ser vazio');
        }
        if (mb_strlen($value) > 100) {
            throw new \InvalidArgumentException('LINE deve ter no máximo 100 caracteres');
        }
        $words = preg_split('/\s+/u', $value) ?: [];
        if (count($words) > 4) {
            throw new \InvalidArgumentException('LINE rejeitada: mais de 4 palavras (evita copiar título)');
        }
        $this->value = $value;
        $this->evidence = $evidence;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function evidence(): Evidence
    {
        return $this->evidence;
    }

    public function equals(self $other): bool
    {
        return mb_strtolower($this->value) === mb_strtolower($other->value);
    }
}
