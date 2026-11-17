<?php

declare(strict_types=1);

namespace Proxyma\Application\Execution;

use DOMDocument;
use LengthException;
use LogicException;
use Packman\Attributes\Api;
use Proxyma\Domain\Execution\DecodedTextBody;
use Proxyma\Domain\Execution\ResponseProfile;

#[Api(since: '1.0')]
final class ResponseDecoder
{
    public function decode(string $bytes, ?string $contentType, ResponseProfile $profile): DecodedTextBody
    {
        if (strlen($bytes) > $profile->maxEntityBytes) {
            throw new LengthException('Entity byte limit exceeded.');
        }
        $encoding = null;
        foreach (["\x00\x00\xFE\xFF" => 'UTF-32BE', "\xFF\xFE\x00\x00" => 'UTF-32LE', "\xEF\xBB\xBF" => 'UTF-8', "\xFE\xFF" => 'UTF-16BE', "\xFF\xFE" => 'UTF-16LE'] as $bom => $name) {
            if (str_starts_with($bytes, $bom)) {
                $encoding = $name;
                $bytes = substr($bytes, strlen($bom));
                break;
            }
        }
        if ($encoding === null && preg_match('/\bcharset\s*=\s*[\x22\x27]?([a-zA-Z0-9_.:-]+)/i', $contentType ?? '', $match) === 1) {
            $encoding = $match[1];
        }
        if ($encoding === null && $bytes !== '' && str_contains(strtolower($contentType ?? ''), 'html')) {
            $document = new DOMDocument;
            $document->loadHTML(substr($bytes, 0, 4096), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            foreach ($document->getElementsByTagName('meta') as $tag) {
                $charset = $tag->getAttribute('charset');
                if ($charset !== '') {
                    $encoding = $charset;
                    break;
                }
                if (strtolower($tag->getAttribute('http-equiv')) === 'content-type'
                    && preg_match('/\bcharset\s*=\s*[\x22\x27]?([a-zA-Z0-9_.:-]+)/i', $tag->getAttribute('content'), $match) === 1) {
                    $encoding = $match[1];
                    break;
                }
            }
        }
        $encoding ??= $profile->charsetDefault;
        $nonTextEncodings = ['BASE64', 'UUENCODE', 'HTML-ENTITIES', 'QUOTED-PRINTABLE', '7BIT', '8BIT'];
        $supported = [];
        foreach (mb_list_encodings() as $name) {
            if (in_array(strtoupper($name), $nonTextEncodings, true)) {
                continue;
            }
            $supported[strtolower($name)] = $name;
            foreach (mb_encoding_aliases($name) as $alias) {
                $supported[strtolower($alias)] = $name;
            }
        }
        $canonical = $supported[strtolower($encoding ?? '')] ?? null;
        if ($canonical === null || ! mb_check_encoding($bytes, $canonical)) {
            throw new LogicException('Invalid or unsupported text encoding.');
        }
        $text = '';
        foreach (mb_str_split($bytes, 16384, $canonical) as $chunk) {
            $utf8 = mb_convert_encoding($chunk, 'UTF-8', $canonical);
            if (! is_string($utf8) || mb_convert_encoding($utf8, $canonical, 'UTF-8') !== $chunk) {
                throw new LogicException('Text conversion failed.');
            }
            if (strlen($text) + strlen($utf8) > $profile->maxTextBytes) {
                throw new LengthException('Text byte limit exceeded.');
            }
            $text .= $utf8;
        }

        return new DecodedTextBody($text, $canonical);
    }
}
