<?php

declare(strict_types=1);

use Proxyma\Application\Execution\ResponseDecoder;
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
    expect(fn () => $reader->finish())->toThrow(LogicException::class);
    $reader->close();
    expect(fn () => new ResponseReader(new ResponseProfile, responseScope(), 'br'))->toThrow(LogicException::class);
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

it('декодирует BOM, header, HTML meta и CSV default без подмены bytes', function (): void {
    $decoder = new ResponseDecoder;
    $profile = new ResponseProfile('text', charsetDefault: 'Windows-1251');
    $text = 'Привет';
    expect($decoder->decode("\xEF\xBB\xBF".$text, 'text/plain; charset=Windows-1251', $profile)->text)->toBe($text)
        ->and($decoder->decode(mb_convert_encoding($text, 'Windows-1251'), 'text/csv', $profile)->text)->toBe($text);
    $html = '<meta charset="Windows-1251">'.$text;
    expect($decoder->decode(mb_convert_encoding($html, 'Windows-1251'), 'text/html', $profile)->text)->toBe($html)
        ->and(fn () => $decoder->decode("\xff", 'text/plain; charset=UTF-8', $profile))->toThrow(LogicException::class)
        ->and(fn () => $decoder->decode('YWJj', 'text/plain; charset=BASE64', $profile))->toThrow(LogicException::class)
        ->and(fn () => $decoder->decode(str_repeat("\xcf", 6), null, new ResponseProfile('text', maxTextBytes: 10, charsetDefault: 'Windows-1251')))->toThrow(LengthException::class);
});
