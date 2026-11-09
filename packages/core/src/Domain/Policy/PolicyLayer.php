<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use InvalidArgumentException;
use Proxyma\Domain\Execution\Uuid;

final readonly class PolicyLayer
{
    /** @var ?list<string> */
    public ?array $allowedProfileKeys;

    /** @var list<BudgetConstraint> */
    public array $budgets;

    /**
     * @param  ?list<string>  $allowedProfileKeys
     * @param  list<BudgetConstraint>  $budgets
     */
    public function __construct(
        public PolicyLayerKind $kind,
        public string $scopeKey,
        public SettingPatch $patch,
        public int $revision = 1,
        public ?string $profileKey = null,
        public ?Uuid $profileId = null,
        public ?int $profileRevision = null,
        ?array $allowedProfileKeys = null,
        public ?string $outcomePolicyKey = null,
        array $budgets = [],
        public ?SubjectRef $subject = null,
    ) {
        if ($scopeKey === '' || preg_match('/[\x00-\x20\x7f]/', $scopeKey) === 1) {
            throw new InvalidArgumentException('PolicyLayer scopeKey must be a non-empty identifier without whitespace.');
        }

        if ($revision < 1) {
            throw new InvalidArgumentException('PolicyLayer revision must be at least 1.');
        }

        if ($profileKey !== null && preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $profileKey) !== 1) {
            throw new InvalidArgumentException('PolicyLayer profileKey must be a valid canonical identifier.');
        }

        if ($profileRevision !== null && $profileRevision < 1) {
            throw new InvalidArgumentException('PolicyLayer profileRevision must be at least 1.');
        }

        if ($outcomePolicyKey !== null && preg_match('/^[a-z0-9][a-z0-9_.:-]{0,63}$/D', $outcomePolicyKey) !== 1) {
            throw new InvalidArgumentException('PolicyLayer outcomePolicyKey must be a valid canonical identifier.');
        }

        if ($allowedProfileKeys !== null) {
            $normalizedAllowed = [];
            foreach ($allowedProfileKeys as $allowedKey) {
                if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $allowedKey) !== 1) {
                    throw new InvalidArgumentException('Allowed profile keys must be valid canonical identifiers.');
                }
                if (! in_array($allowedKey, $normalizedAllowed, true)) {
                    $normalizedAllowed[] = $allowedKey;
                }
            }
            $this->allowedProfileKeys = $normalizedAllowed;
        } else {
            $this->allowedProfileKeys = null;
        }

        $this->budgets = $budgets;
    }
}
