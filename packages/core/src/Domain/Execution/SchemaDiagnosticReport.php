<?php

declare(strict_types=1);

namespace Proxyma\Domain\Execution;

use InvalidArgumentException;

final readonly class SchemaDiagnosticReport
{
    /** @var list<string> */
    public array $missingTables;

    /** @var list<string> */
    public array $missingConstraints;

    /**
     * @param  list<string>  $missingTables
     * @param  list<string>  $missingConstraints
     */
    public function __construct(
        public bool $healthy,
        public string $databaseEngine,
        array $missingTables = [],
        array $missingConstraints = [],
    ) {
        if ($databaseEngine === '') {
            throw new InvalidArgumentException('Database engine name must not be empty.');
        }

        if ($healthy && ($missingTables !== [] || $missingConstraints !== [])) {
            throw new InvalidArgumentException('Schema report cannot be healthy when tables or constraints are missing.');
        }

        $this->missingTables = $missingTables;
        $this->missingConstraints = $missingConstraints;
    }
}
