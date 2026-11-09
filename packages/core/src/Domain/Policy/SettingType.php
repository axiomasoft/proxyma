<?php

declare(strict_types=1);

namespace Proxyma\Domain\Policy;

enum SettingType: string
{
    case Boolean = 'boolean';
    case Integer = 'integer';
    case Identifier = 'identifier';
    case IdentifierList = 'identifier_list';
    case IntegerList = 'integer_list';
}
