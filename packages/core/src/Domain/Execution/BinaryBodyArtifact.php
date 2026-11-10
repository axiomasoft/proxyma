<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use Closure;
use LogicException;
use Proxyma\Infrastructure\Database\ConnectionScope;

/** Exclusive temporary lease. The consumer must accept or close it outside SQL. */
final class BinaryBodyArtifact implements ResponseBody
{
    private bool $closed = false;

    /** @param resource $stream */
    public function __construct(private mixed $stream, private readonly int $length, private readonly ContentHash $hash,
        private readonly ConnectionScope $scope) {}

    public function byteLength(): int
    {
        return $this->length;
    }

    public function contentHash(): ContentHash
    {
        return $this->hash;
    }

    /** The callback must finish copying before returning; it must not retain the stream. */
    public function accept(Closure $consumer): mixed
    {
        $this->scope->assertOutsideTransaction();
        if ($this->closed || ! is_resource($this->stream)) {
            throw new LogicException('Artifact lease is closed.');
        }
        rewind($this->stream);
        $hash = hash_init('sha256');
        $length = hash_update_stream($hash, $this->stream);
        if ($length !== $this->length || ! hash_equals($this->hash->hex, hash_final($hash))) {
            $this->close();
            throw new LogicException('Artifact integrity mismatch.');
        }
        rewind($this->stream);
        try {
            return $consumer($this->stream, $this->length, $this->hash);
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        $this->scope->assertOutsideTransaction();
        if (! $this->closed) {
            $this->closed = true;
            fclose($this->stream);
        }
    }

    public function __serialize(): array
    {
        throw new LogicException('Artifact leases cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('Artifact leases cannot be reconstructed.');
    }

    private function __clone() {}

    public function __debugInfo(): array
    {
        return ['length' => $this->length, 'hash' => $this->hash->hex];
    }
}
