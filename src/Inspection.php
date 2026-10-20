<?php

declare(strict_types=1);

namespace Survos\BagIt;

final readonly class Inspection
{
    /**
     * @param list<Diagnostic> $errors
     * @param list<Diagnostic> $warnings
     */
    public function __construct(public array $errors, public ?Verification $verification, public array $warnings = [])
    {
        if (([] === $errors) !== (null !== $verification)) {
            throw new \InvalidArgumentException('Only a successful inspection may contain verification.');
        }
    }

    public function isValid(): bool
    {
        return [] === $this->errors;
    }
}
