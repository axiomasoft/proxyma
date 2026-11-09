<?php

declare(strict_types=1);

namespace Proxyma\Contracts;

use Proxyma\Domain\Policy\PolicyDefinition;

interface PolicyDefinitionProvider
{
    /**
     * @return list<PolicyDefinition>
     */
    public function definitions(): array;
}
