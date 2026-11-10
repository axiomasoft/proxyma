<?php

declare(strict_types=1);

use Proxyma\Application\Recovery\CompletionCoordinator;
use Proxyma\Application\Recovery\Reconciler;
use Proxyma\Domain\Execution\AttemptResult;
use Proxyma\Domain\Execution\ContentHash;
use Proxyma\Domain\Execution\DecodedTextBody;
use Proxyma\Domain\Execution\LateReceiptClaim;
use Proxyma\Domain\Execution\ResponseDescriptor;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthEpochMap;
use Proxyma\Infrastructure\Database\CompletionObservationReducer;
use Proxyma\Infrastructure\Database\ReconcileRepository;

$pdo->prepare("SELECT set_config('application_name',?,false)")->execute(['proxyma-p42-'.$schema.'-'.$payload['label']]);
$repository = new ReconcileRepository($scope, $locks, $demands, $budgets, $quotas);
if ($payload['recovery_action'] === 'reconcile') {
    $result = (new Reconciler($transactions, $repository, $access))->run($tenant, $payload['limit'] ?? 32);
    echo json_encode(['status' => 'reconciled', 'page' => $result], JSON_THROW_ON_ERROR);

    return;
}
if ($payload['recovery_action'] === 'complete') {
    $attempt = $db->table('pxm_attempts')->where('operation_id', $payload['operation'])->where('physical_attempt_id', $payload['attempt'])->first();
    $response = new ResponseDescriptor($payload['receipt_status'] ?? 200, ['x-fixture' => ['safe'], 'retry-after' => ['120']], new DecodedTextBody('fixture'), 7, 7);
    $receipt = new LateReceiptClaim(new Uuid($payload['operation']), new Uuid($payload['attempt']), new ContentHash($attempt->wire_fingerprint),
        'fixture-receipt', new ContentHash(hash('sha256', 'fixture')), new HealthEpochMap(json_decode($attempt->snapshot, true)['epochs']), $response);
    $result = (new CompletionCoordinator($transactions, $repository, $access, $resolver, new CompletionObservationReducer($scope)))->recordLateReceipt($receipt);
    echo json_encode(['status' => $result->outcome->value], JSON_THROW_ON_ERROR);

    return;
}
function recoveryCheckpoint(string $name): void
{
    global $payload,$db;
    if ($payload['checkpoint'] === $name) {
        echo json_encode(['ready' => $name], JSON_THROW_ON_ERROR)."\n";
        flush();
        $db->select('SELECT pg_advisory_lock(hashtextextended(?,0))', ['fixture:recovery:'.$payload['label']]);
        throw new RuntimeException('Crash barrier unexpectedly released.');
    }
}
recoveryCheckpoint('before_reserve');
$permit = $service->reserve($ticket, $input);
recoveryCheckpoint('after_reserve');
$candidate = $claims->claim($permit, $input, $wire);
recoveryCheckpoint('after_authorization');
$claims->activate($candidate)->consume();
$db->table('fixture_receipts')->insert(['physical_attempt_id' => $input->request->physicalAttemptId->value, 'bytes' => 1]);
recoveryCheckpoint('after_fixture_bytes');
$result = AttemptResult::delivered(new ResponseDescriptor(200, [], new DecodedTextBody('fixture'), 7, 7));
recoveryCheckpoint('before_receipt');
throw new RuntimeException('Crash checkpoint not selected.');
