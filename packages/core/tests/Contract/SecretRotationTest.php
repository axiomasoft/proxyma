<?php

declare(strict_types=1);

use Illuminate\Encryption\Encrypter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Application\Catalog\CatalogConflict;
use Proxyma\Application\Catalog\CatalogInvalidMutation;
use Proxyma\Application\Catalog\CatalogStorageFailure;
use Proxyma\Application\Catalog\RotateCredentials;
use Proxyma\Contracts\Security\SecretResolver;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Catalog\CredentialRef;
use Proxyma\Domain\Catalog\EgressIdentity;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Execution\PreparedHeaders;
use Proxyma\Domain\Execution\ResolvedSecret;
use Proxyma\Domain\Execution\RouteSelection;
use Proxyma\Domain\Execution\TlsTrustConfiguration;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Secrets\EncryptedSecretStore;
use Proxyma\Infrastructure\Secrets\SecretStorageFailure;
use Proxyma\Infrastructure\Security\SecretRedactor;
use Proxyma\Tests\Support\CatalogTestCase;
use Proxyma\Tests\Support\ClaimFixtures;
use Proxyma\Tests\Support\DemandFixtures;
use Proxyma\Tests\Support\ReservationFixtures;

use function Proxyma\Tests\Support\fixtureDestination;

require_once __DIR__.'/../Support/CatalogTestCase.php';
require_once __DIR__.'/../Support/SelectionFixtures.php';
require_once __DIR__.'/../Support/DemandFixtures.php';
require_once __DIR__.'/../Support/ReservationFixtures.php';
require_once __DIR__.'/../Support/ClaimFixtures.php';

uses(CatalogTestCase::class, DemandFixtures::class, ReservationFixtures::class, ClaimFixtures::class)->group('pg');

beforeEach(function (): void {
    $this->initializeClaimFixture();
    $this->proxyClaimFixture();
    $this->app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    $this->rotation = $this->app->make(RotateCredentials::class);
    $this->secrets = $this->app->make(SecretResolver::class);
});

it('encrypted store migration изолирована и обратима вместе с package schema', function (): void {
    $migrationPath = dirname(__DIR__, 2).'/database/migrations';
    $this->artisan('migrate:rollback', ['--path' => $migrationPath, '--realpath' => true, '--force' => true])->assertExitCode(0);
    expect($this->db->select('SELECT tablename FROM pg_tables WHERE schemaname = current_schema()'))->toHaveCount(1);
    $this->artisan('migrate', ['--path' => $migrationPath, '--realpath' => true, '--force' => true])->assertExitCode(0);
    expect($this->table('encrypted_secrets')->count())->toBe(0)->and($this->db->table('migrations')->count())->toBe(15);
});

it('по умолчанию сохраняет только ciphertext и opaque reference, атомарно продвигает authority и audit', function (): void {
    $actor = $this->actor(1);
    $endpoint = $this->rotation->handle($this->proxyEndpoint, $this->tenant, 'sentinel-user', 'sentinel-password@/%', $actor);
    $stored = $this->table('encrypted_secrets')->first();
    $secret = $this->secrets->resolve($endpoint->credential, $this->tenant);
    expect($this->secrets)->toBeInstanceOf(EncryptedSecretStore::class)
        ->and($endpoint->credential->reference)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($endpoint->credentialVersion())->toBe(2)->and($endpoint->revision)->toBe(2)
        ->and($this->table('routes')->value('revision'))->toBe(3)
        ->and($secret->revealSecret())->toBe('sentinel-password@/%')->and($secret->username)->toBe('sentinel-user')
        ->and(json_encode($stored))->not->toContain('sentinel-user', 'sentinel-password');
    $audit = $this->table('audit_events')->where('ability', 'catalog.endpoint.rotate-credentials')->get();
    expect($audit)->toHaveCount(1)->and(json_encode($audit))->not->toContain('sentinel-user', 'sentinel-password')
        ->and(json_encode($this->table('catalog_command_receipts')->get()))->not->toContain('sentinel-user', 'sentinel-password');
    $replay = $this->rotation->handle($this->proxyEndpoint, $this->tenant, 'sentinel-user', 'sentinel-password@/%', $actor);
    expect($replay->credential->equals($endpoint->credential))->toBeTrue()
        ->and($this->table('encrypted_secrets')->count())->toBe(1);
    expect(fn () => $this->rotation->handle($this->proxyEndpoint, $this->tenant, 'sentinel-user', 'another-password', $actor))
        ->toThrow(CatalogConflict::class);
});

it('первый secret сменяет implicit version 1, последующая замена отзывает старую ссылку', function (): void {
    $this->table('endpoints')->update(['credential_ref' => null, 'credential_version' => null, 'revision' => 2]);
    $first = $this->rotation->handle($this->proxyEndpoint, $this->tenant, null, 'first-password', $this->actor(2));
    $second = $this->rotation->handle($this->proxyEndpoint, $this->tenant, null, 'second-password', $this->actor(3));
    expect($first->credentialVersion())->toBe(2)->and($second->credentialVersion())->toBe(3)
        ->and($first->credential->reference)->not->toBe($second->credential->reference)
        ->and($this->secrets->resolve($first->credential, $this->tenant))->toBeNull()
        ->and($this->secrets->resolve($second->credential, $this->tenant)->revealSecret())->toBe('second-password');
});

it('отсутствующий secret и неверная version не разрешаются', function (): void {
    $endpoint = $this->rotation->handle($this->proxyEndpoint, $this->tenant, 'user', 'password', $this->actor(1));
    expect($this->secrets->resolve(new CredentialRef('missing-secret'), $this->tenant))->toBeNull()
        ->and($this->secrets->resolve(new CredentialRef($endpoint->credential->reference, 99), $this->tenant))->toBeNull();
    $this->table('endpoints')->update(['retired_at' => $this->db->raw('clock_timestamp()'), 'revision' => 3]);
    expect($this->secrets->resolve($endpoint->credential, $this->tenant))->toBeNull();
    expect(fn () => $this->rotation->handle($this->proxyEndpoint, $this->tenant, 'user', 'new-password', $this->actor(3)))
        ->toThrow(CatalogInvalidMutation::class);
});

it('trusted tenant и permission проверяются при каждом resolve и replay', function (): void {
    $actor = $this->actor(1);
    $endpoint = $this->rotation->handle($this->proxyEndpoint, $this->tenant, 'user', 'password', $actor);
    $this->useTenant(new TenantKey('foreign'));
    expect(fn () => $this->secrets->resolve($endpoint->credential, $this->tenant))->toThrow(AuthorizationDenied::class);
    expect(fn () => $this->rotation->handle($this->proxyEndpoint, $this->tenant, 'user', 'password', $actor))
        ->toThrow(AuthorizationDenied::class);
    $this->useTenant($this->tenant);
    $this->permissions(static fn (): false => false);
    expect(fn () => $this->secrets->resolve($endpoint->credential, $this->tenant))->toThrow(AuthorizationDenied::class);
    expect(fn () => $this->rotation->handle($this->proxyEndpoint, $this->tenant, 'user', 'password', $actor))
        ->toThrow(AuthorizationDenied::class);
});

it('ошибка audit откатывает ciphertext, endpoint, route revisions и receipt', function (): void {
    $first = $this->rotation->handle($this->proxyEndpoint, $this->tenant, 'user', 'old-password', $this->actor(1));
    $ciphertext = $this->table('encrypted_secrets')->value('ciphertext');
    $audits = $this->table('audit_events')->count();
    $receipts = $this->table('catalog_command_receipts')->count();
    $this->db->statement("CREATE FUNCTION fixture_fail_rotation_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'fixture audit failure'; END $$");
    $this->db->statement('CREATE TRIGGER fixture_fail_audit BEFORE INSERT ON pxm_audit_events FOR EACH ROW EXECUTE FUNCTION fixture_fail_rotation_audit()');
    try {
        $this->rotation->handle($this->proxyEndpoint, $this->tenant, 'secret-user-marker', 'secret-password-marker', $this->actor(2));
        $this->fail('Expected audit rollback.');
    } catch (CatalogStorageFailure $error) {
        expect($error->getPrevious())->toBeNull()
            ->and((string) $error)->not->toContain('secret-user-marker', 'secret-password-marker', $ciphertext);
    }
    expect($this->table('encrypted_secrets')->value('ciphertext'))->toBe($ciphertext)
        ->and($this->table('endpoints')->value('revision'))->toBe(2)
        ->and($this->table('routes')->value('revision'))->toBe(3)
        ->and($this->table('audit_events')->count())->toBe($audits)
        ->and($this->table('catalog_command_receipts')->count())->toBe($receipts)
        ->and($this->secrets->resolve($first->credential, $this->tenant)->revealSecret())->toBe('old-password');
});

it('rotation между reserve и claim отклоняет подготовленный secret без charges и bytes', function (): void {
    [$permit, $input, $wire] = $this->prepareClaim();
    $before = $this->accounting();
    $this->rotation->handle($this->proxyEndpoint, $this->tenant, 'new-user', 'new-password', $this->actor(1));
    expect(fn () => $this->claims->claim($permit, $input, $wire))->toThrow(AdmissionRejected::class, 'endpoint_revision_changed');
    expect($this->accounting())->toBe($before)->and($this->table('attempts')->count())->toBe(0)->and($this->emitted)->toBe(0);
});

it('missing prepared secret отклоняется final CAS, корректный default resolved secret допускается', function (): void {
    $endpoint = $this->rotation->handle($this->proxyEndpoint, $this->tenant, 'user', 'password', $this->actor(1));
    $intent = $this->demandOperation();
    $intent['route'] = new RouteSelection($this->catalog['route'], RouteKind::Proxy,
        $this->catalog['profile'], $this->proxyEndpoint, $endpoint->credentialVersion(), EgressIdentity::explicit('nat:fixture'));
    $input = $this->reservationInput($intent);
    $destination = fixtureDestination($this->transactions->scope, $intent['request']);
    $missing = $this->sealer->seal($intent['request'], $input->route, $destination, new TlsTrustConfiguration);
    $intent['request'] = $missing->request;
    $missingInput = $this->reservationInput($intent);
    $ticket = $this->reservations->enqueue($missingInput, $this->work);
    $permit = $this->reservations->reserve($ticket, $missingInput);
    $this->claims->prepare($permit, $missingInput, $missing);
    expect(fn () => $this->claims->claim($permit, $missingInput, $missing))->toThrow(AdmissionRejected::class, 'prepared_credential_conflict');
    expect($this->table('attempts')->count())->toBe(0);
    $this->reservations->cancel($this->tenant, $missingInput->operation->operationId, $permit->permitId);
    $intent = $this->demandOperation();
    $intent['route'] = new RouteSelection($this->catalog['route'], RouteKind::Proxy,
        $this->catalog['profile'], $this->proxyEndpoint, $endpoint->credentialVersion(), EgressIdentity::explicit('nat:fixture'));
    $input = $this->reservationInput($intent);
    $wire = $this->sealer->seal($intent['request'], $input->route, $destination, new TlsTrustConfiguration,
        $this->secrets->resolve($endpoint->credential, $this->tenant));
    $intent['request'] = $wire->request;
    $input = $this->reservationInput($intent);
    $permit = $this->reservations->reserve($this->reservations->enqueue($input, $this->work), $input);
    $this->claims->prepare($permit, $input, $wire);
    $candidate = $this->claims->claim($permit, $input, $wire);
    $this->emit($this->claims->activate($candidate));
    expect($this->emitted)->toBe(1)->and($this->table('attempts')->count())->toBe(1);
});

it('не выполняет encrypt или decrypt в SQL transaction, включая raw PDO', function (): void {
    $endpoint = $this->rotation->handle($this->proxyEndpoint, $this->tenant, 'user', 'password', $this->actor(1));
    foreach (['laravel', 'pdo'] as $mode) {
        $mode === 'laravel' ? $this->db->beginTransaction() : $this->db->getPdo()->beginTransaction();
        try {
            expect(fn () => $this->secrets->resolve($endpoint->credential, $this->tenant))->toThrow(LogicException::class, 'transactionLevel zero');
            expect(fn () => $this->rotation->handle($this->proxyEndpoint, $this->tenant, 'user', 'new-password', $this->actor(2)))
                ->toThrow(LogicException::class, 'transactionLevel zero');
        } finally {
            $mode === 'laravel' ? $this->db->rollBack() : $this->db->getPdo()->rollBack();
        }
    }
});

it('tampered ciphertext, неверный key и подмена payload identity закрывают decrypt без утечки', function (string $failure): void {
    $endpoint = $this->rotation->handle($this->proxyEndpoint, $this->tenant, 'marker-user', 'marker-password', $this->actor(1));
    if ($failure === 'key') {
        $this->app->instance('encrypter', new Encrypter(str_repeat('x', 32), 'AES-256-CBC'));
        $this->secrets = $this->app->make(SecretResolver::class);
    } elseif ($failure === 'identity') {
        $ciphertext = $this->app['encrypter']->encrypt(json_encode(['foreign', $endpoint->credential->reference, 2, 'marker-user', 'marker-password']), false);
        $this->table('encrypted_secrets')->update(['ciphertext' => $ciphertext]);
    } else {
        $this->table('encrypted_secrets')->update(['ciphertext' => 'tampered-marker-password']);
    }
    try {
        $this->secrets->resolve($endpoint->credential, $this->tenant);
        $this->fail('Expected fail-closed decryption.');
    } catch (SecretStorageFailure $error) {
        expect($error->getPrevious())->toBeNull()->and((string) $error)->not->toContain('marker-user', 'marker-password');
    }
})->with(['tampered', 'key', 'identity']);

it('previous encryption key читает старый secret, rotation переписывает новым key', function (): void {
    $first = $this->rotation->handle($this->proxyEndpoint, $this->tenant, 'user', 'password', $this->actor(1));
    $encrypter = new Encrypter(str_repeat('n', 32), 'AES-256-CBC');
    $encrypter->previousKeys([str_repeat('k', 32)]);
    $this->app->instance('encrypter', $encrypter);
    $store = $this->app->make(SecretResolver::class);
    expect($store->resolve($first->credential, $this->tenant)->revealSecret())->toBe('password');
    $next = $this->app->make(RotateCredentials::class)->handle($this->proxyEndpoint, $this->tenant, 'user', 'password', $this->actor(2));
    $encrypter->previousKeys([]);
    expect($store->resolve($next->credential, $this->tenant)->revealSecret())->toBe('password');
});

it('кодирует delimiters отдельно и отклоняет malformed credentials без значений в exception', function (): void {
    $secret = new ResolvedSecret(new CredentialRef('opaque'), 'u@/% +ю', 'p:@/% +ю');
    expect($secret->encodedUserInfo())->toBe(rawurlencode('u@/% +ю').':'.rawurlencode('p:@/% +ю'));
    foreach ([['bad:user', 'password'], ['user', "password\r\n"], ['user', "bad\0"], ['user', "bad\x01"], ['user', "bad\xff"], ['user', ''], ['', 'password']] as [$user, $password]) {
        try {
            new ResolvedSecret(new CredentialRef('opaque'), $user, $password);
            $this->fail('Expected malformed credential denial.');
        } catch (InvalidArgumentException $error) {
            expect($error->getMessage())->not->toContain('bad:user', 'password')->and(json_encode($error->getTrace()))->not->toContain('bad:user', 'bad\\u0000');
        }
    }
});

it('secret scanning исключений, log context, diagnostics и serialized request не находит sentinels', function (): void {
    $secret = new ResolvedSecret(new CredentialRef('opaque'), 'canary-user@', 'canary-password:/%');
    $redactor = new SecretRedactor([$secret]);
    $basic = base64_encode($secret->username.':'.$secret->revealSecret());
    $raw = ['authorization' => 'Basic '.$basic, 'diagnostic' => 'failure '.$secret->encodedUserInfo(),
        'nested' => ['url' => 'https://user:password@example.test/path?api_key=other-canary',
            'exception' => new RuntimeException('canary-password:/%', 0, new RuntimeException('canary-user@'))],
        'free_text' => $secret->revealSecret().' '.rawurlencode($secret->revealSecret()), 'object' => $secret];
    $safe = json_encode($redactor->context($raw), JSON_THROW_ON_ERROR);
    $handler = new TestHandler;
    $logger = new Logger('fixture-security', [$handler]);
    $logger->error('request_failed', $redactor->context($raw));
    $logged = json_encode($handler->getRecords(), JSON_THROW_ON_ERROR);
    $intent = $this->demandOperation(url: 'https://example.test/path?canary=other-canary');
    $request = $this->modifiedWire($intent['request'], ['headers' => new PreparedHeaders(['authorization' => 'Basic '.$basic])]);
    foreach ([$safe, $logged, json_encode($request), json_encode($secret), print_r($request, true), print_r($secret, true), print_r($redactor, true)] as $output) {
        expect($output)->not->toContain('canary-user', 'canary-password', $basic, 'other-canary', rawurlencode($secret->revealSecret()));
    }
    expect(fn (): string => serialize($request))->toThrow(LogicException::class);
    expect(fn (): string => serialize($secret))->toThrow(LogicException::class);
    try {
        new PreparedHeaders(['canary-password:/%' => 'value']);
        $this->fail('Expected malformed header denial.');
    } catch (InvalidArgumentException $error) {
        expect((string) $error)->not->toContain('canary-password');
    }
});
