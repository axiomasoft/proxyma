<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Override;
use Packman\Attributes\Internal;
use Proxyma\Infrastructure\Database\PackageSchema;

/** @internal Табличные имена и чтение; изменения выполняют application commands. */
#[Internal]
abstract class AuthorityRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    #[Override]
    public function getTable(): string
    {
        $base = parent::getTable();
        $noun = str_starts_with($base, 'pxm_') ? substr($base, 4) : $base;

        return PackageSchema::prefix().$noun;
    }

    public function save(array $options = [])
    {
        throw new LogicException('Authority records are internal; use application commands.');
    }

    public function delete()
    {
        throw new LogicException('Authority records cannot be deleted through the ORM.');
    }
}
