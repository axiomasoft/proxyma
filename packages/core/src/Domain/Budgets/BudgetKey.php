<?php

declare(strict_types=1);

namespace Proxyma\Domain\Budgets;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Proxyma\Domain\Policy\TenantKey;

#[Api(since: '1.0')]
final readonly class BudgetKey
{
    public function __construct(public TenantKey $tenant, public string $scope)
    {
        if ($scope === '' || strlen($scope) > 255 || preg_match('/[\x00-\x20\x7f]/', $scope) === 1) {
            throw new InvalidArgumentException('Budget scope must be a bounded canonical key.');
        }
    }

    public function orderKey(): string
    {
        return $this->scope."\0".$this->tenant->value;
    }
}
