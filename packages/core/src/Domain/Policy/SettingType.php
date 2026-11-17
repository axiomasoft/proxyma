<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum SettingType: string
{
    case Boolean = 'boolean';
    case Integer = 'integer';
    case Identifier = 'identifier';
    case IdentifierList = 'identifier_list';
    case IntegerList = 'integer_list';
}
