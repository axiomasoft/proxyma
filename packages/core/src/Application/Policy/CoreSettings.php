<?php

declare(strict_types=1);

namespace Proxyma\Application\Policy;

use Packman\Attributes\Api;
use Proxyma\Domain\Policy\PolicyScope;
use Proxyma\Domain\Policy\SettingDescriptor;
use Proxyma\Domain\Policy\SettingMerge;
use Proxyma\Domain\Policy\SettingType;

#[Api(since: '1.0')]
final class CoreSettings
{
    /**
     * @param  list<string>  $strategyKeys
     * @return list<SettingDescriptor>
     */
    public static function descriptors(array $strategyKeys = []): array
    {
        $scopes = [PolicyScope::Global, PolicyScope::Tenant, PolicyScope::Panel, PolicyScope::Entity];

        return [
            new SettingDescriptor('timeout.connect_ms', SettingType::Integer, 1000, false, $scopes, SettingMerge::Minimum, 1, 120000, 'policy.timeout.edit'),
            new SettingDescriptor('timeout.read_ms', SettingType::Integer, 10000, false, $scopes, SettingMerge::Minimum, 1, 120000, 'policy.timeout.edit'),
            new SettingDescriptor('timeout.total_ms', SettingType::Integer, 10000, false, $scopes, SettingMerge::Minimum, 1, 300000, 'policy.timeout.edit'),
            new SettingDescriptor('retry.max_attempts', SettingType::Integer, 1, false, $scopes, SettingMerge::Minimum, 1, 10, 'policy.retry.edit'),
            new SettingDescriptor('retry.delays_ms', SettingType::IntegerList, [], false, $scopes, SettingMerge::Replace, 0, 300000, 'policy.retry.edit', optional: true),
            new SettingDescriptor('http.max_redirects', SettingType::Integer, 0, false, $scopes, SettingMerge::Minimum, 0, 10, 'policy.http.edit'),
            new SettingDescriptor('route.allow_direct', SettingType::Boolean, false, false, $scopes, SettingMerge::Replace, null, null, 'policy.route.edit'),
            new SettingDescriptor('routes.denied', SettingType::IdentifierList, [], false, $scopes, SettingMerge::Union, null, null, 'policy.route.edit'),
            new SettingDescriptor('destinations.allowed', SettingType::IdentifierList, null, true, $scopes, SettingMerge::Intersection, null, null, 'policy.destination.edit'),
            new SettingDescriptor('route.strategy', SettingType::Identifier, 'uniform_random', false, $scopes, SettingMerge::Replace, null, null, 'policy.route.edit',
                allowedValues: array_values(array_unique(['uniform_random', 'least_recent_authorized', 'budget_aware_random', ...$strategyKeys]))),
        ];
    }
}
