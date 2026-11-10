<?php

declare(strict_types=1);

namespace Proxyma\Application\Health;

use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Catalog\CatalogMutationActor;

final readonly class ProbeReview
{
    public function __construct(public array $versions, public array $epochs, public CatalogMutationActor $actor, public string $hash) {}

    public function assertMatches(array $evidence): void
    {
        $epochs = $evidence['epochs'];
        ksort($epochs, SORT_STRING);
        $expected = $this->epochs;
        ksort($expected, SORT_STRING);
        if ($epochs === [] || $epochs !== $expected || $this->actor->expectedRevision !== ($this->versions['policy'] ?? null)) {
            throw new AdmissionRejected('probe_review_changed');
        }
        $required = array_unique(['policy', 'policy_hash', ...array_keys($epochs), ...array_filter(array_keys($evidence['versions']),
            static fn (string $key): bool => preg_match('/^(profile|route|target|endpoint):/', $key) === 1)]);
        if (count($required) !== count($this->versions)) {
            throw new AdmissionRejected('probe_review_changed');
        }
        foreach ($required as $key) {
            if (! isset($this->versions[$key]) || $this->versions[$key] !== ($evidence['versions'][$key] ?? null)) {
                throw new AdmissionRejected('probe_review_changed');
            }
        }
    }
}
