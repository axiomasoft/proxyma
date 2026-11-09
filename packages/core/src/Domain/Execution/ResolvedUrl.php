<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;

final readonly class ResolvedUrl
{
    /** @var non-empty-list<string> */
    public array $addresses;

    /**
     * @param  list<string>  $addresses
     */
    public function __construct(
        public SafeUrl $url,
        public string $address,
        array $addresses = [],
    ) {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('Expected a valid IP address.');
        }

        $normalized = [$address];
        foreach ($addresses as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgumentException('Expected a list of valid IP addresses.');
            }
            if (! in_array($candidate, $normalized, true)) {
                $normalized[] = $candidate;
            }
        }

        $this->addresses = $normalized;
    }

    public function withAddress(string $address): self
    {
        if (! in_array($address, $this->addresses, true)) {
            throw new InvalidArgumentException('Address must belong to the resolved candidate set.');
        }

        return new self($this->url, $address, $this->addresses);
    }
}
