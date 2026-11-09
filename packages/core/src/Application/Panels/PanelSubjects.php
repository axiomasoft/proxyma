<?php

declare(strict_types=1);

namespace Proxyma\Application\Panels;

use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Domain\Panels\SubjectResolutionFailed;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;

final readonly class PanelSubjects
{
    public function __construct(private DefinitionRegistry $definitions) {}

    public function resolve(string $panelKey, SubjectRef $leaf): SubjectChain
    {
        $panel = $this->definitions->panel($panelKey);
        $definition = $this->definitions->subjectResolver($panel->subjectResolver);
        if (! in_array($leaf->type, $definition->types, true)) {
            throw new SubjectResolutionFailed('Unregistered subject type.');
        }
        $chain = $definition->resolver->resolve($leaf->withoutParents());
        if ($chain === null || ! $chain->leaf->equals($leaf->withoutParents())) {
            throw new SubjectResolutionFailed('Subject is missing or belongs to another scope.');
        }
        // Каждый узел подтверждает свой префикс: чужая или устаревшая parent chain не становится authority.
        $ancestors = $chain->ancestors();
        foreach ($ancestors as $index => $ancestor) {
            if (! in_array($ancestor->type, $definition->types, true)) {
                throw new SubjectResolutionFailed('Unregistered ancestor type.');
            }
            $resolved = $definition->resolver->resolve($ancestor);
            $expected = new SubjectChain($ancestor, array_slice($ancestors, 0, $index));
            if ($resolved === null || array_map(static fn (SubjectRef $s): string => $s->key(), $resolved->ordered)
                !== array_map(static fn (SubjectRef $s): string => $s->key(), $expected->ordered)) {
                throw new SubjectResolutionFailed('Orphan subject or conflicting parent.');
            }
        }
        if ($leaf->parents !== [] && ! $leaf->equals(new SubjectRef($leaf->tenant, $leaf->type, $leaf->id, $ancestors))) {
            throw new SubjectResolutionFailed('Caller ancestry conflicts with resolver.');
        }

        return $chain;
    }

    public function verify(string $panelKey, SubjectChain $supplied): void
    {
        $actual = $this->resolve($panelKey, $supplied->leaf);
        if (array_map(static fn (SubjectRef $s): string => $s->key(), $actual->ordered)
            !== array_map(static fn (SubjectRef $s): string => $s->key(), $supplied->ordered)) {
            throw new SubjectResolutionFailed('Caller chain differs from registered ancestry.');
        }
    }
}
