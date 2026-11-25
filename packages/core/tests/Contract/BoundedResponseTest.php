<?php

declare(strict_types=1);

use Proxyma\Application\Execution\ResponseDecoder;
use Proxyma\Domain\Execution\DecodedTextBody;
use Proxyma\Domain\Execution\ResponseBody;
use Proxyma\Domain\Execution\ResponseProfile;
use Proxyma\Infrastructure\Http\ResponseReader;

require_once __DIR__.'/../Support/ResponseFixtures.php';

it('ограничивает wire и распакованное тело независимо', function (): void {
    $reader = new ResponseReader(new ResponseProfile('raw', 5, 100, 100), responseScope());
    expect(fn () => $reader->write('123456'))->toThrow(LengthException::class);
    $reader->close();
    $reader = new ResponseReader(new ResponseProfile('raw', 100, 10, 100), responseScope(), 'gzip');
    expect(fn () => $reader->write(gzencode(str_repeat('x', 100))))->toThrow(LengthException::class);
    $reader->close();
    $reader = new ResponseReader(new ResponseProfile, responseScope(), 'gzip');
    $reader->write(substr(gzencode('text'), 0, -2));
    expect(fn (): ResponseBody => $reader->finish())->toThrow(LogicException::class);
    $reader->close();
    expect(fn (): ResponseReader => new ResponseReader(new ResponseProfile, responseScope(), 'br'))->toThrow(LogicException::class);
});

it('собирает разбитый gzip и учитывает каждый предел', function (): void {
    $wire = gzencode('Привет');
    $reader = new ResponseReader(new ResponseProfile, responseScope(), 'gzip');
    foreach (str_split($wire, 3) as $chunk) {
        $reader->write($chunk);
    }
    expect($reader->finish()->bytes())->toBe('Привет')
        ->and($reader->wireBytes())->toBe(strlen($wire))
        ->and($reader->decodedBytes())->toBe(strlen('Привет'));
    $reader->close();
});

it('binary artifact содержит распакованные bytes и hash без смешения с wire accounting', function (): void {
    $content = "%PDF-1.4\n".str_repeat('local fixture', 128);
    $wire = gzencode($content);
    $reader = new ResponseReader(new ResponseProfile('binary', 65536, 65536, 65536), responseScope(), 'gzip');
    foreach (str_split($wire, 3) as $chunk) {
        $reader->write($chunk);
    }
    $artifact = $reader->finish();
    expect($reader->wireBytes())->toBe(strlen($wire))
        ->and($reader->decodedBytes())->toBe(strlen($content))
        ->and($artifact->contentHash()->hex)->toBe(hash('sha256', $content));
    $artifact->accept(function ($stream, $length) use ($content): void {
        expect($length)->toBe(strlen($content))->and(stream_get_contents($stream))->toBe($content);
    });
});

it('binary ceiling допускает конечный Agelto download profile без расширения text buffering', function (): void {
    expect((new ResponseProfile('binary', 268435456, 268435456, 268435456))->maxEntityBytes)->toBe(268435456)
        ->and(fn (): ResponseProfile => new ResponseProfile('binary', 268435457, 268435456, 268435456))->toThrow(InvalidArgumentException::class)
        ->and(fn (): ResponseProfile => new ResponseProfile('raw', 67108865, 67108865, 67108865))->toThrow(InvalidArgumentException::class);
});

it('декодирует BOM, header, HTML meta и CSV default без подмены bytes', function (): void {
    $decoder = new ResponseDecoder;
    $profile = new ResponseProfile('text', charsetDefault: 'Windows-1251');
    $text = 'Привет';
    expect($decoder->decode("\xEF\xBB\xBF".$text, 'text/plain; charset=Windows-1251', $profile)->text)->toBe($text)
        ->and($decoder->decode(mb_convert_encoding($text, 'Windows-1251'), 'text/csv', $profile)->text)->toBe($text);
    $html = '<meta charset="Windows-1251">'.$text;
    expect($decoder->decode(mb_convert_encoding($html, 'Windows-1251'), 'text/html', $profile)->text)->toBe($html)
        ->and(fn (): DecodedTextBody => $decoder->decode("\xff", 'text/plain; charset=UTF-8', $profile))->toThrow(LogicException::class)
        ->and(fn (): DecodedTextBody => $decoder->decode('YWJj', 'text/plain; charset=BASE64', $profile))->toThrow(LogicException::class)
        ->and(fn (): DecodedTextBody => $decoder->decode(str_repeat("\xcf", 6), null, new ResponseProfile('text', maxTextBytes: 10, charsetDefault: 'Windows-1251')))->toThrow(LengthException::class);
});
