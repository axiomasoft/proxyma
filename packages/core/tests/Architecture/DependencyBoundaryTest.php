<?php

declare(strict_types=1);

use Proxyma\Contracts\OutboundClient;
use Proxyma\Domain\Admission\LockPosition;
use Proxyma\Domain\Execution\AuthorizedWireRequest;
use Proxyma\Domain\Execution\BinaryBodyArtifact;
use Proxyma\Domain\Execution\ClaimCandidate;
use Proxyma\Domain\Execution\CommitLatch;
use Proxyma\Domain\Execution\ResolvedUrl;
use Proxyma\Domain\Policy\TenantKey;

final class UnexpectedMutableDomainFixture
{
    public int $value = 0;
}

it('keeps core completely independent of App, Filament, ProcessCore, and provider-specific headers', function (): void {
    $coreRoot = dirname(__DIR__, 2).'/src';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($coreRoot));
    $checkedFiles = 0;

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $checkedFiles++;
        $source = (string) file_get_contents($file->getPathname());

        expect($source)->not->toContain(
            'App\\',
            'Filament\\',
            'ProcessCore\\',
            'Database\\Factories\\Procurement',
            'Prism\\',
            'x-goog-api-key',
        );
    }

    expect($checkedFiles)->toBeGreaterThan(30);
});

it('keeps Domain and Contracts completely free of Illuminate, Eloquent, and Symfony dependencies', function (): void {
    $roots = [
        dirname(__DIR__, 2).'/src/Domain',
        dirname(__DIR__, 2).'/src/Contracts',
    ];
    $checkedFiles = 0;

    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $checkedFiles++;
            $source = (string) file_get_contents($file->getPathname());

            expect($source)->not->toContain(
                'Illuminate\\',
                'Eloquent',
                'Filament\\',
                'App\\',
                'Symfony\\',
                'Laravel\\',
            );
        }
    }

    expect($checkedFiles)->toBeGreaterThan(30);
});

it('enforces public contract and immutable domain boundaries without convenience bloat or forgeable capabilities', function (): void {
    $contractsDir = dirname(__DIR__, 2).'/src/Contracts';
    $contractFiles = glob($contractsDir.'/*.php') ?: [];
    expect($contractFiles)->not->toBeEmpty();

    foreach ($contractFiles as $file) {
        $className = 'Proxyma\\Contracts\\'.basename($file, '.php');
        $reflection = new ReflectionClass($className);
        expect($reflection->isInterface())->toBeTrue();
    }

    $outboundClient = new ReflectionClass(OutboundClient::class);
    $methods = array_map(static fn (ReflectionMethod $m): string => $m->getName(), $outboundClient->getMethods());
    expect($methods)->toBe(['execute']);

    $authorizedReflection = new ReflectionClass(AuthorizedWireRequest::class);
    expect($authorizedReflection->isFinal())->toBeTrue()
        ->and($authorizedReflection->getConstructor()?->isPrivate())->toBeTrue();

    $candidateReflection = new ReflectionClass(ClaimCandidate::class);
    expect($candidateReflection->isFinal())->toBeTrue()
        ->and($candidateReflection->getConstructor()?->isPrivate())->toBeTrue();

    $domainRoot = dirname(__DIR__, 2).'/src/Domain';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($domainRoot));
    $mutableAllowed = [
        AuthorizedWireRequest::class,
        ClaimCandidate::class,
        CommitLatch::class,
        LockPosition::class,
        BinaryBodyArtifact::class,
        ResolvedUrl::class,
    ];
    $allowedState = static function (ReflectionClass $class) use ($mutableAllowed): bool {
        if ($class->isReadOnly() || $class->isSubclassOf(Throwable::class) || in_array($class->getName(), $mutableAllowed, true)) {
            return true;
        }

        return $class->getProperties() === []
            && array_all($class->getMethods(), static fn (ReflectionMethod $method): bool => $method->isStatic());
    };
    expect($allowedState(new ReflectionClass(UnexpectedMutableDomainFixture::class)))->toBeFalse();
    foreach ((new ReflectionClass(ResolvedUrl::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        expect($property->isReadOnly())->toBeTrue();
    }

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($domainRoot) + 1, -4);
        $fqcn = 'Proxyma\\Domain\\'.str_replace('/', '\\', $relative);
        $reflection = new ReflectionClass($fqcn);

        if ($reflection->isInterface() || $reflection->isEnum()) {
            continue;
        }

        expect($reflection->isFinal())->toBeTrue();
        expect($allowedState($reflection))->toBeTrue($fqcn);
    }

    $config = require dirname(__DIR__, 2).'/config/proxyma.php';
    expect($config['default_tenant'])->toBe(TenantKey::SYSTEM)
        ->and($config['safety'])->toBe([
            'admission' => 'mandatory',
            'budgets' => 'mandatory',
            'health' => 'mandatory',
        ]);
});
