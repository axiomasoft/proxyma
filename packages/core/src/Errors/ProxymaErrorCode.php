<?php

declare(strict_types=1);

namespace Proxyma\Errors;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Stable wire error codes for Proxyma runtime, catalog, policy, and secret failures.
 */
#[Api(since: '1.0')]
enum ProxymaErrorCode: string implements ErrorCode
{
    case AuthorizationDenied = 'proxyma.authorization_denied';
    case CatalogConflict = 'proxyma.catalog_conflict';
    case CatalogInvalidMutation = 'proxyma.catalog_invalid_mutation';
    case CatalogNotFound = 'proxyma.catalog_not_found';
    case CatalogStorageFailure = 'proxyma.catalog_storage_failure';
    case AdmissionRejected = 'proxyma.admission_rejected';
    case ReservationDeferred = 'proxyma.reservation_deferred';
    case NeedsReplan = 'proxyma.needs_replan';
    case PolicyConflict = 'proxyma.policy_conflict';
    case InvalidSettingPatch = 'proxyma.invalid_setting_patch';
    case PolicyStorageFailure = 'proxyma.policy_storage_failure';
    case DefinitionError = 'proxyma.definition_error';
    case SubjectResolutionFailed = 'proxyma.subject_resolution_failed';
    case SecretStorageFailure = 'proxyma.secret_storage_failure';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }

    public function category(): ErrorCategory
    {
        return match ($this) {
            self::AuthorizationDenied => ErrorCategory::Authorization,
            self::CatalogInvalidMutation,
            self::InvalidSettingPatch,
            self::DefinitionError => ErrorCategory::Validation,
            self::CatalogNotFound,
            self::SubjectResolutionFailed => ErrorCategory::NotFound,
            self::CatalogConflict,
            self::AdmissionRejected,
            self::PolicyConflict,
            self::NeedsReplan => ErrorCategory::Conflict,
            self::ReservationDeferred => ErrorCategory::RateLimit,
            self::CatalogStorageFailure,
            self::PolicyStorageFailure,
            self::SecretStorageFailure => ErrorCategory::Internal,
        };
    }

    public function defaultStatus(): int
    {
        return match ($this) {
            self::AuthorizationDenied => 403,
            self::CatalogNotFound,
            self::SubjectResolutionFailed => 404,
            self::CatalogConflict,
            self::AdmissionRejected,
            self::PolicyConflict,
            self::NeedsReplan => 409,
            self::CatalogInvalidMutation,
            self::InvalidSettingPatch,
            self::DefinitionError => 422,
            self::ReservationDeferred => 429,
            self::CatalogStorageFailure,
            self::PolicyStorageFailure,
            self::SecretStorageFailure => 500,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::AuthorizationDenied => 'Catalog access denied',
            self::CatalogConflict => 'Catalog revision or identity conflict',
            self::CatalogInvalidMutation => 'Invalid catalog mutation',
            self::CatalogNotFound => 'Catalog entity not found',
            self::CatalogStorageFailure => 'Catalog storage or audit failed',
            self::AdmissionRejected => 'Joint admission rejected',
            self::ReservationDeferred => 'Admission reservation deferred',
            self::NeedsReplan => 'Quota window ended; attempt requires replanning',
            self::PolicyConflict => 'Policy revision or idempotency conflict',
            self::InvalidSettingPatch => 'Invalid policy setting patch',
            self::PolicyStorageFailure => 'Policy storage or audit failed',
            self::DefinitionError => 'Invalid panel or extension definition',
            self::SubjectResolutionFailed => 'Subject ancestry resolution failed',
            self::SecretStorageFailure => 'Credential storage or decryption failed',
        };
    }

    public function isRetryable(): bool
    {
        return match ($this) {
            self::ReservationDeferred,
            self::NeedsReplan => true,
            default => false,
        };
    }

    /** @return list<string> */
    public function detailKeys(): array
    {
        return match ($this) {
            self::AdmissionRejected,
            self::ReservationDeferred => ['reason'],
            default => [],
        };
    }
}
