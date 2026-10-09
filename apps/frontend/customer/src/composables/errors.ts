import { ApiError } from '@bw/api-client';

/** Translation key for a failed request. Server texts are never shown, only stable codes. */
export function errorKey(error: unknown): string {
  if (!(error instanceof ApiError)) {
    return 'common.error_generic';
  }

  if (error.status === 0) {
    return 'common.error_network';
  }

  if (error.status === 403) {
    return 'common.error_forbidden';
  }

  if (error.status === 404) {
    return 'common.error_not_found';
  }

  if (error.status === 409 || error.status === 412 || error.code === 'version_conflict') {
    return 'common.error_conflict';
  }

  if (error.status === 429) {
    return 'common.error_rate_limited';
  }

  if (error.code === 'store_not_verified') {
    return 'store.errors.store_not_verified';
  }

  if (error.status === 422) {
    return 'common.error_validation';
  }

  return 'common.error_generic';
}

export function requestIdOf(error: unknown): string | null {
  return error instanceof ApiError ? error.requestId : null;
}

export function fieldErrorsOf(error: unknown): string[] {
  return error instanceof ApiError ? Object.keys(error.fieldErrors) : [];
}
