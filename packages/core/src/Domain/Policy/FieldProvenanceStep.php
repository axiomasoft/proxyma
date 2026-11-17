<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class FieldProvenanceStep
{
    public mixed $inputValue;

    public mixed $effectiveValueAfter;

    public function __construct(
        public PolicyLayerKind $layerKind,
        public string $scopeKey,
        public int $revision,
        public string $operation,
        mixed $inputValue,
        mixed $effectiveValueAfter,
        public bool $clamped = false,
        public string $reason = 'applied',
        public bool $sensitive = false,
    ) {
        if ($scopeKey === '' || $operation === '' || $reason === '' || $revision < 1) {
            throw new InvalidArgumentException('FieldProvenanceStep requires non-empty scopeKey, operation, reason, and positive revision.');
        }

        $this->inputValue = $sensitive ? null : $inputValue;
        $this->effectiveValueAfter = $sensitive ? null : $effectiveValueAfter;
    }
}
