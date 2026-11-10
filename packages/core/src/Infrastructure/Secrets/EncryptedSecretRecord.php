<?php

declare(strict_types=1);

namespace Proxyma\Infrastructure\Secrets;

use Illuminate\Database\Eloquent\Attributes\Table;
use Proxyma\Infrastructure\Eloquent\AuthorityRecord;

/** @internal */
#[Table(name: 'pxm_encrypted_secrets')]
final class EncryptedSecretRecord extends AuthorityRecord
{
    protected $hidden = ['ciphertext'];
}
