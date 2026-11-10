<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Http;

use LogicException;
use Proxyma\Domain\Execution\BinaryBodyArtifact;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\RawResponseBody;
use Proxyma\Domain\Execution\ResponseBody;
use Proxyma\Domain\Execution\ResponseProfile;
use Proxyma\Infrastructure\Database\ConnectionScope;

final class ResponseReader
{
    private mixed $stream;

    private mixed $inflater = null;

    private int $wire = 0;

    private int $decoded = 0;

    private string $text = '';

    private bool $finished = false;

    public function __construct(private readonly ResponseProfile $profile, private readonly ConnectionScope $scope, string $encoding = '')
    {
        $scope->assertOutsideTransaction();
        $encoding = strtolower(trim($encoding));
        if (! in_array($encoding, ['', 'identity', 'gzip', 'deflate'], true)) {
            throw new LogicException('Unsupported content encoding.');
        }
        if ($encoding === 'gzip' || $encoding === 'deflate') {
            $this->inflater = inflate_init($encoding === 'gzip' ? ZLIB_ENCODING_GZIP : ZLIB_ENCODING_DEFLATE);
        }
        $this->stream = tmpfile();
        if ($this->stream === false || $this->inflater === false) {
            throw new LogicException('Cannot create response sink.');
        }
    }

    public function write(string $chunk): void
    {
        $this->wire += strlen($chunk);
        if ($this->wire > $this->profile->maxWireBytes) {
            throw new \LengthException('Wire byte limit exceeded.');
        }
        if ($this->profile->kind === 'binary' && fwrite($this->stream, $chunk) !== strlen($chunk)) {
            throw new LogicException('Response sink write failed.');
        }
        foreach (str_split($chunk, 512) as $part) {
            $decoded = $this->inflater === null ? $part : @inflate_add($this->inflater, $part, ZLIB_SYNC_FLUSH);
            if ($decoded === false) {
                throw new LogicException('Invalid compressed response.');
            }
            $this->decoded += strlen($decoded);
            if ($this->decoded > $this->profile->maxEntityBytes) {
                throw new \LengthException('Entity byte limit exceeded.');
            }
            if ($this->profile->kind !== 'binary') {
                $this->text .= $decoded;
            }
        }
    }

    public function finish(): ResponseBody
    {
        if ($this->inflater !== null && (inflate_get_status($this->inflater) !== ZLIB_STREAM_END
            || inflate_get_read_len($this->inflater) !== $this->wire)) {
            throw new LogicException('Truncated compressed response.');
        }
        $this->finished = true;
        if ($this->profile->kind !== 'binary') {
            fclose($this->stream);

            return new RawResponseBody($this->text);
        }
        rewind($this->stream);
        $hash = hash_init('sha256');
        hash_update_stream($hash, $this->stream);

        return new BinaryBodyArtifact($this->stream, $this->wire, new ContentHash(hash_final($hash)), $this->scope);
    }

    public function wireBytes(): int
    {
        return $this->wire;
    }

    public function decodedBytes(): int
    {
        return $this->decoded;
    }

    public function close(): void
    {
        if (! $this->finished && is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->finished = true;
    }
}
