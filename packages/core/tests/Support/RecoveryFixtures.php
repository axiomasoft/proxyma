<?php

declare(strict_types=1);

namespace Proxyma\Tests\Support;

use Proxyma\Application\Authorization\CatalogAccess;
use Proxyma\Application\Recovery\CompletionCoordinator;
use Proxyma\Application\Recovery\OwnedTemporaryArtifacts;
use Proxyma\Application\Recovery\Reconciler;
use Proxyma\Application\Recovery\RetentionPruner;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\DecodedTextBody;
use Proxyma\Domain\Execution\LateReceiptClaim;
use Proxyma\Domain\Execution\ResponseDescriptor;
use Proxyma\Domain\Health\HealthEpochMap;
use Proxyma\Infrastructure\Database\ReconcileRepository;

trait RecoveryFixtures
{
    protected function initializeRecoveryFixture(): void
    {
        $this->initializeQuotaFixture();
        $this->sqlClock('2030-06-01T10:10:00Z');
        $this->quota->configure($this->tenant, $this->originBudget, 20, 40, 1);
        $this->completion = $this->app->make(CompletionCoordinator::class);
        $this->reconciler = $this->app->make(Reconciler::class);
        $this->pruner = $this->app->make(RetentionPruner::class);
        $this->tempRoot = dirname(__DIR__, 4).'/build/'.$this->fixtureSchema.'/tmp';
        $this->artifacts = new OwnedTemporaryArtifacts($this->transactions, $this->app->make(ReconcileRepository::class),
            $this->app->make(CatalogAccess::class), $this->tempRoot);
    }

    protected function delivered(string $body = 'fixture'): AttemptResult
    {
        return AttemptResult::delivered(new ResponseDescriptor(200, ['x-fixture' => ['safe']], new DecodedTextBody($body), strlen($body), strlen($body)));
    }

    protected function late(array $prepared, string $receipt = 'fixture-receipt', string $body = 'fixture'): LateReceiptClaim
    {
        [, $input] = $prepared;
        $a = $this->table('attempts')->where('physical_attempt_id', $input->request->physicalAttemptId->value)->first();

        return new LateReceiptClaim($input->operation->operationId, $input->request->physicalAttemptId, $input->request->fingerprint(), $receipt,
            new ContentHash(hash('sha256', $body)), new HealthEpochMap(json_decode($a->snapshot, true)['epochs']), $this->delivered($body)->response);
    }

    protected function authorized(): array
    {
        $prepared = $this->quotaPrepared();
        [$permit, $input, $wire] = $prepared;
        $this->claims->claim($permit, $input, $wire);

        return $prepared;
    }
}
