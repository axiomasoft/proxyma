<?php

declare(strict_types=1);

use Proxyma\Domain\Execution\BinaryBodyArtifact;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\ResponseProfile;
use Proxyma\Infrastructure\Http\ResponseReader;

require_once __DIR__.'/../Support/ResponseFixtures.php';

it('передаёт binary bytes один раз и закрывает sink после ошибки consumer', function (): void {
    $reader = new ResponseReader(new ResponseProfile('binary'), responseScope());
    $wire = gzencode('Привет');
    $reader->write($wire);
    $artifact = $reader->finish();
    expect($artifact->contentHash()->hex)->toBe(hash('sha256', $wire));
    expect(fn () => $artifact->accept(function ($stream) use ($wire): void {
        expect(stream_get_contents($stream))->toBe($wire);
        throw new RuntimeException('consumer failed');
    }))->toThrow(RuntimeException::class);
    expect(fn () => $artifact->accept(fn () => null))->toThrow(LogicException::class);
    $reader->close();
});

it('принимает binary lease и закрывает его после копирования', function (): void {
    $reader = new ResponseReader(new ResponseProfile('binary'), responseScope());
    $reader->write("\0binary\xff");
    $artifact = $reader->finish();
    expect($artifact->accept(fn ($stream) => stream_get_contents($stream)))->toBe("\0binary\xff")
        ->and(fn () => $artifact->accept(fn () => null))->toThrow(LogicException::class);
    $reader->close();
});

it('отклоняет повреждённый artifact до передачи', function (): void {
    $stream = tmpfile();
    fwrite($stream, 'wrong');
    $artifact = new BinaryBodyArtifact($stream, 5, ContentHash::of('right'), responseScope());
    $called = false;
    expect(fn () => $artifact->accept(function () use (&$called): void { $called = true; }))->toThrow(LogicException::class)
        ->and($called)->toBeFalse()->and(is_resource($stream))->toBeFalse();
});

it('запрещает создание sink и приём artifact внутри транзакции', function (): void {
    expect(fn () => new ResponseReader(new ResponseProfile('binary'), responseScope(true)))->toThrow(LogicException::class);
    $stream = tmpfile();
    fwrite($stream, 'x');
    $artifact = new BinaryBodyArtifact($stream, 1, ContentHash::of('x'), responseScope(true));
    expect(fn () => $artifact->accept(fn () => null))->toThrow(LogicException::class);
    fclose($stream);
});
