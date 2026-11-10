<?php

declare(strict_types=1);

namespace Proxyma\Laravel\Console;

use Illuminate\Console\Command;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Catalog\CatalogConflict;
use Proxyma\Application\Diagnostics\AdmissionDiagnosis;
use Proxyma\Application\Diagnostics\Doctor;
use Proxyma\Application\Diagnostics\OperationsMetrics;
use Proxyma\Application\Health\ResetHealth;
use Proxyma\Application\Maintenance\BoundedProbe;
use Proxyma\Application\Recovery\Reconciler;
use Proxyma\Application\Recovery\RetentionPruner;
use Proxyma\Contracts\TenantContextResolver;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Execution\ExecutionBlocked;
use Proxyma\Domain\Execution\ExecutionDeferred;
use Proxyma\Domain\Execution\ExecutionSucceeded;
use Proxyma\Domain\Execution\ExecutionUnknownAfterAuthorization;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Health\HealthScope;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;

abstract class OperationsCommand extends Command
{
    protected const ACTION = '';

    public function handle(): int
    {
        try {
            if (static::ACTION === 'doctor') {
                $result = $this->laravel->make(Doctor::class)->inspect();
                $this->outputJson($result);

                return $result['healthy'] ? self::SUCCESS : self::FAILURE;
            }
            $context = $this->laravel->make(TenantContextResolver::class)->resolve();
            $tenant = $context->tenant;
            if (static::ACTION === 'reconcile') {
                $this->line(json_encode($this->laravel->make(Reconciler::class)->run($tenant, $this->integerOption('limit', 1, 64)), JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            } elseif (static::ACTION === 'prune') {
                $result = ['removed' => $this->laravel->make(RetentionPruner::class)->prune($tenant,
                    $this->integerOption('retention', 1, 2147483647), $this->integerOption('limit', 1, 64))];
            } elseif (static::ACTION === 'metrics') {
                $result = $this->laravel->make(OperationsMetrics::class)->snapshot($tenant, $this->integerOption('limit', 1, 64));
            } elseif (static::ACTION === 'reset') {
                $actor = new CatalogMutationActor($context->actor, (string) $this->option('reason'),
                    $this->integerOption('expected-revision', 1, PHP_INT_MAX), (string) $this->option('idempotency-key'));
                $row = $this->laravel->make(ResetHealth::class)->handle(HealthScope::from((string) $this->argument('scope')),
                    new Uuid((string) $this->argument('id')), $tenant, $actor);
                $result = ['state' => $row->state, 'revision' => $row->revision, 'health_epoch' => $row->health_epoch];
            } else {
                $subjects = new SubjectChain(new SubjectRef($tenant, (string) $this->option('subject-type'), (string) $this->option('subject-id')));
                $profile = new Uuid((string) $this->argument('profile'));
                $target = new Uuid((string) $this->argument('target'));
                if (static::ACTION === 'probe') {
                    $outcome = $this->laravel->make(BoundedProbe::class)->run($subjects, $profile,
                        new Uuid((string) $this->argument('route')), $target, $this->integerOption('seconds', 16, 60));
                    $this->outputJson(['succeeded' => $outcome instanceof ExecutionSucceeded,
                        'attempts' => match (true) {
                            $outcome instanceof ExecutionUnknownAfterAuthorization => 1,
                            $outcome instanceof ExecutionSucceeded, $outcome instanceof ExecutionBlocked, $outcome instanceof ExecutionDeferred => count($outcome->attemptIds),
                            default => throw new \LogicException('Unsupported execution result.'),
                        },
                        'reason' => isset($outcome->reason) ? $outcome->reason->value : null]);

                    return $outcome instanceof ExecutionSucceeded ? self::SUCCESS : self::FAILURE;
                }
                $result = $this->laravel->make(AdmissionDiagnosis::class)->explain($subjects, $profile, $target, (string) $this->option('traffic'));
            }
            $this->outputJson($result);

            return self::SUCCESS;
        } catch (AuthorizationDenied) {
            $this->outputJson(['error' => 'permission_denied']);

            return self::FAILURE;
        } catch (CatalogConflict) {
            $this->outputJson(['error' => 'revision_or_idempotency_conflict']);

            return self::FAILURE;
        } catch (\InvalidArgumentException|\ValueError) {
            $this->outputJson(['error' => 'invalid_input']);

            return self::INVALID;
        } catch (\Throwable) {
            // Exceptions may contain DSNs, URLs and credentials, including vendor errors.
            $this->outputJson(['error' => 'operation_failed']);

            return self::FAILURE;
        }
    }

    private function integerOption(string $name, int $minimum, int $maximum): int
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);

        return $value === false ? throw new \InvalidArgumentException('Invalid integer option.') : $value;
    }

    private function outputJson(array $result): void
    {
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
