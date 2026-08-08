<?php

declare(strict_types=1);

namespace App\ValueObjects\HiddenSeo;

/**
 * MPN (Manufacturer Part Number) — identificador curto com evidência.
 */
final class Mpn
{
    private string $value;
    private Evidence $evidence;

    public function __construct(string $value, Evidence $evidence)
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException('MPN não pode ser vazio');
        }
        if (mb_strlen($value) > 60) {
            throw new \InvalidArgumentException('MPN deve ter no máximo 60 caracteres');
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
