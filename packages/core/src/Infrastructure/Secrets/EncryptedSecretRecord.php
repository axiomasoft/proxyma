<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Secrets;

use Illuminate\Database\Eloquent\Attributes\Table;
use Packman\Attributes\Internal;
use Proxyma\Infrastructure\Eloquent\AuthorityRecord;

/** @internal */
#[Table(name: 'pxm_encrypted_secrets')]
#[Internal]
final class EncryptedSecretRecord extends AuthorityRecord
{
    protected $hidden = ['ciphertext'];
}
