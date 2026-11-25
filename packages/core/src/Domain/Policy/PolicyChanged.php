<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class PolicyChanged
{
    /** @var list<string> */
    public array $changedFields;

    /** @var list<string> */
    public array $changedLayers;

    /**
     * @param  list<string>  $changedFields
     * @param  list<string>  $changedLayers
     */
    public function __construct(
        public PinnedPolicySnapshot $pinned,
        public EffectivePolicy $livePolicy,
        array $changedFields,
        array $changedLayers,
        public string $reason = 'policy_revision_changed',
    ) {
        if ($reason === '' || ($changedFields === [] && $changedLayers === [])) {
            throw new InvalidArgumentException('PolicyChanged requires a non-empty reason and at least one changed field or layer.');
        }

        $fields = array_values(array_unique($changedFields));
        sort($fields, SORT_STRING);
        $this->changedFields = $fields;

        $layers = array_values(array_unique($changedLayers));
        sort($layers, SORT_STRING);
        $this->changedLayers = $layers;
    }
}
