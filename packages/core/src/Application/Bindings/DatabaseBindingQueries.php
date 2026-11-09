<?php

declare(strict_types=1);

namespace Proxyma\Application\Bindings;

use Illuminate\Database\Connection;
use Proxyma\Contracts\BindingQueries;
use Proxyma\Domain\Policy\SubjectPolicyBinding;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Infrastructure\Database\BindingStorage;

final readonly class DatabaseBindingQueries implements BindingQueries
{
    public function __construct(private Connection $db, private BindingContext $context) {}

    public function findBinding(SubjectRef $subject, string $panelKey = 'default', string $slotKey = 'default'): ?SubjectPolicyBinding
    {
        $this->context->authorize($subject, $panelKey, $slotKey, 'view');
        $storage = new BindingStorage($this->db);
        $row = $storage->query($subject, $panelKey, $slotKey)->first();

        return $row === null ? null : $storage->map($row, $subject);
    }
}
