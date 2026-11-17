<?php

declare(strict_types=1);

namespace Proxyma\Domain\Panels;

use Packman\Attributes\Api;
use Proxyma\Contracts\Bindings\SubjectResolver;

#[Api(since: '1.0')]
final readonly class SubjectResolverDefinition
{
    /** @param array<array-key, string> $types */
    public function __construct(
        public string $id,
        public SubjectResolver $resolver,
        public array $types,
        public int $version = 1,
    ) {
        PanelSlot::identifier($id);
        if ($types === [] || ! array_is_list($types) || count(array_unique($types)) !== count($types) || $version < 1) {
            throw new DefinitionError('Resolver requires unique subject types and a positive version.');
        }
        foreach ($types as $type) {
            if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $type) !== 1) {
                throw new DefinitionError('Invalid subject type.');
            }
        }
    }
}
