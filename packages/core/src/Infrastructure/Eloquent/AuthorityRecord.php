<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** @internal Табличные имена и чтение; изменения выполняют application commands. */
abstract class AuthorityRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    public function save(array $options = [])
    {
        throw new LogicException('Authority records are internal; use application commands.');
    }

    public function delete()
    {
        throw new LogicException('Authority records cannot be deleted through the ORM.');
    }
}
