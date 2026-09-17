import { beforeEach, describe, expect, it } from 'vitest';
import { ApiError } from '@/api/client';
import { locales } from '@/i18n';
import { useUiStore } from '@/stores/uiStore';
import { errorCodeToI18nKey, resolveErrorText } from '@/lib/errorMessages';

function lookup(source: unknown, path: string): string | undefined {
  let current: unknown = source;
  for (const key of path.split('.')) {
    if (current && typeof current === 'object' && key in (current as Record<string, unknown>)) {
      current = (current as Record<string, unknown>)[key];
    } else {
      return undefined;
    }
  }
  return typeof current === 'string' ? current : undefined;
}

beforeEach(() => {
  useUiStore.setState({ language: 'zh' });
});

describe('errorCodeToI18nKey', () => {
  it('covers the http and transport error codes', () => {
    expect(errorCodeToI18nKey).toMatchObject({
      unauthorized: 'errors.unauthorized',
      forbidden: 'errors.forbidden',
      not_found: 'errors.notFound',
      server_error: 'errors.serverError',
      network_error: 'errors.network',
      rate_limited: 'errors.rateLimited',
      validation_error: 'errors.validationError',
      bad_request: 'errors.badRequest',
      upload_failed: 'errors.uploadFailed',
      aborted: 'errors.aborted',
      timeout: 'errors.timeout',
    });
  });

  it('points at a message that exists in both languages', () => {
    for (const path of Object.values(errorCodeToI18nKey)) {
      expect(lookup(locales.zh, path), path).toBeTruthy();
      expect(lookup(locales.en, path), path).toBeTruthy();
    }
  });
});

describe('resolveErrorText', () => {
  it('passes plain strings through', () => {
    expect(resolveErrorText('boom')).toBe('boom');
    expect(resolveErrorText('')).toBe('');
  });

  it('returns an empty string for values that carry no message', () => {
    expect(resolveErrorText(null)).toBe('');
    expect(resolveErrorText(undefined)).toBe('');
    expect(resolveErrorText(42)).toBe('');
    expect(resolveErrorText({})).toBe('');
  });

  it('translates a known error code for the active language', () => {
    const error = new ApiError('unauthorized', 'Session expired', 401);
    expect(resolveErrorText(error)).toBe(locales.zh.errors.unauthorized);

    useUiStore.setState({ language: 'en' });
    expect(resolveErrorText(error)).toBe(locales.en.errors.unauthorized);
  });

  it('translates a code derived from the http status', () => {
    expect(resolveErrorText(new ApiError(404, 'gone'))).toBe(locales.zh.errors.notFound);
    expect(resolveErrorText(new ApiError(429, 'slow down'))).toBe(locales.zh.errors.rateLimited);
    expect(resolveErrorText(new ApiError(500, 'broken'))).toBe(locales.zh.errors.serverError);
  });

  it('translates transport level codes', () => {
    expect(resolveErrorText({ code: 'network_error' })).toBe(locales.zh.errors.network);
    expect(resolveErrorText({ code: 'aborted' })).toBe(locales.zh.errors.aborted);
    expect(resolveErrorText({ code: 'timeout' })).toBe(locales.zh.errors.timeout);
  });

  it('falls back to the message for an unknown code', () => {
    expect(resolveErrorText({ code: 'something_else', message: 'raw message' })).toBe(
      'raw message'
    );
    expect(resolveErrorText(new ApiError('custom_code', 'custom message', 409))).toBe(
      'custom message'
    );
  });

  it('uses the message when no code is present', () => {
    expect(resolveErrorText({ message: 'plain failure' })).toBe('plain failure');
    expect(resolveErrorText(new Error('native failure'))).toBe('native failure');
  });

  it('ignores non string codes and messages', () => {
    expect(resolveErrorText({ code: 401, message: 500 })).toBe('');
  });

  it('falls back to chinese for an unsupported language', () => {
    useUiStore.setState({ language: 'fr' as never });
    expect(resolveErrorText(new ApiError('forbidden', 'no', 403))).toBe(
      locales.zh.errors.forbidden
    );
  });
});
