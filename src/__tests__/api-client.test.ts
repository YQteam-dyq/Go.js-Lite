import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  ApiError,
  ApprovalPendingError,
  apiFetch,
  clearCsrfToken,
  getCsrfToken,
  isApprovalPending,
  setCsrfToken,
} from '@/api/client';

interface MockResponseInit {
  status?: number;
  statusText?: string;
  contentType?: string;
  body?: string;
  blob?: unknown;
}

function makeResponse(init: MockResponseInit = {}): Response {
  const status = init.status ?? 200;
  const body = init.body ?? '';
  const contentType = init.contentType ?? 'application/json';
  return {
    ok: status >= 200 && status < 300,
    status,
    statusText: init.statusText ?? 'OK',
    headers: {
      get: (name: string) => (name.toLowerCase() === 'content-type' ? contentType : null),
    },
    text: () => Promise.resolve(body),
    json: () => Promise.resolve(JSON.parse(body)),
    blob: () => Promise.resolve(init.blob),
  } as unknown as Response;
}

function stubFetch(response: Response) {
  const mock = vi.fn().mockResolvedValue(response);
  vi.stubGlobal('fetch', mock);
  return mock;
}

async function captureError(promise: Promise<unknown>): Promise<ApiError> {
  try {
    await promise;
  } catch (err) {
    return err as ApiError;
  }
  throw new Error('the request was expected to fail');
}

function readCall(mock: unknown) {
  const calls = (mock as { mock: { calls: unknown[][] } }).mock.calls;
  const call = calls[calls.length - 1] ?? [];
  return {
    url: String(call[0]),
    init: (call[1] ?? {}) as RequestInit,
    headers: ((call[1] as RequestInit | undefined)?.headers ?? {}) as Record<string, string>,
  };
}

beforeEach(() => {
  document.head.innerHTML = '';
  document.cookie = 'csrf_token=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('apiFetch request building', () => {
  it('prefixes relative paths with the api base and strips a leading slash', async () => {
    const fetchMock = stubFetch(makeResponse({ body: '{"ok":true,"data":1}' }));
    await apiFetch('ping');
    expect(readCall(fetchMock).url).toBe('/gojs/api/ping');

    await apiFetch('/ping');
    expect(readCall(fetchMock).url).toBe('/gojs/api/ping');
  });

  it('keeps absolute urls untouched', async () => {
    const fetchMock = stubFetch(makeResponse({ body: '{"ok":true,"data":1}' }));
    await apiFetch('https://example.test/hook');
    expect(readCall(fetchMock).url).toBe('https://example.test/hook');
  });

  it('serializes params and skips null or undefined values', async () => {
    const fetchMock = stubFetch(makeResponse({ body: '{"ok":true,"data":1}' }));
    await apiFetch('list', { params: { page: 2, q: 'a b', empty: null, missing: undefined } });
    expect(readCall(fetchMock).url).toBe('/gojs/api/list?page=2&q=a+b');
  });

  it('appends params to a url that already carries a query string', async () => {
    const fetchMock = stubFetch(makeResponse({ body: '{"ok":true,"data":1}' }));
    await apiFetch('https://example.test/x?keep=1', { params: { page: 3 } });
    expect(readCall(fetchMock).url).toBe('https://example.test/x?keep=1&page=3');
  });

  it('sends the json accept header and includes credentials', async () => {
    const fetchMock = stubFetch(makeResponse({ body: '{"ok":true,"data":1}' }));
    await apiFetch('ping');
    const { init, headers } = readCall(fetchMock);
    expect(headers.Accept).toBe('application/json');
    expect(init.credentials).toBe('include');
    expect(init.method).toBe('GET');
  });

  it('merges custom headers without dropping the defaults', async () => {
    const fetchMock = stubFetch(makeResponse({ body: '{"ok":true,"data":1}' }));
    await apiFetch('ping', { headers: { 'X-Trace': 'abc' } });
    const { headers } = readCall(fetchMock);
    expect(headers.Accept).toBe('application/json');
    expect(headers['X-Trace']).toBe('abc');
  });

  it('stringifies plain bodies as json and keeps an explicit content type', async () => {
    const fetchMock = stubFetch(makeResponse({ body: '{"ok":true,"data":1}' }));
    await apiFetch('save', {
      method: 'POST',
      body: { a: 1 },
      headers: { 'Content-Type': 'application/vnd.api+json' },
    });
    const { init, headers } = readCall(fetchMock);
    expect(headers['Content-Type']).toBe('application/vnd.api+json');
    expect(init.body).toBe('{"a":1}');
  });

  it('defaults to application/json when a body is given without a content type', async () => {
    const fetchMock = stubFetch(makeResponse({ body: '{"ok":true,"data":1}' }));
    await apiFetch('save', { method: 'PUT', body: { a: 2 } });
    const { headers } = readCall(fetchMock);
    expect(headers['Content-Type']).toBe('application/json');
  });

  it('passes binary and form bodies through untouched', async () => {
    const fetchMock = stubFetch(makeResponse({ body: '{"ok":true,"data":1}' }));
    const form = new FormData();
    form.append('name', 'value');
    const buffer = new ArrayBuffer(8);

    await apiFetch('upload', { method: 'POST', body: form });
    expect(readCall(fetchMock).init.body).toBe(form);
    expect(readCall(fetchMock).headers['Content-Type']).toBeUndefined();

    await apiFetch('upload', { method: 'POST', body: buffer });
    expect(readCall(fetchMock).init.body).toBe(buffer);
  });

  it('forwards the abort signal', async () => {
    const fetchMock = stubFetch(makeResponse({ body: '{"ok":true,"data":1}' }));
    const controller = new AbortController();
    await apiFetch('ping', { signal: controller.signal });
    expect(readCall(fetchMock).init.signal).toBe(controller.signal);
  });
});

describe('apiFetch csrf handling', () => {
  it('adds the csrf header when a token is available', async () => {
    setCsrfToken('token-123');
    const fetchMock = stubFetch(makeResponse({ body: '{"ok":true,"data":1}' }));
    await apiFetch('ping');
    expect(readCall(fetchMock).headers['X-CSRF-Token']).toBe('token-123');
  });

  it('omits the csrf header when no token is available', async () => {
    const fetchMock = stubFetch(makeResponse({ body: '{"ok":true,"data":1}' }));
    await apiFetch('ping');
    expect(readCall(fetchMock).headers['X-CSRF-Token']).toBeUndefined();
  });
});

describe('apiFetch response handling', () => {
  it('unwraps the data payload of a successful envelope', async () => {
    stubFetch(makeResponse({ body: '{"ok":true,"data":{"id":7}}' }));
    await expect(apiFetch<{ id: number }>('item')).resolves.toEqual({ id: 7 });
  });

  it('returns null for an explicit null payload', async () => {
    stubFetch(makeResponse({ body: '{"ok":true,"data":null}' }));
    await expect(apiFetch('item')).resolves.toBeNull();
  });

  it('returns the raw body when it is not an envelope', async () => {
    stubFetch(makeResponse({ body: '[1,2,3]' }));
    await expect(apiFetch<number[]>('list')).resolves.toEqual([1, 2, 3]);
  });

  it('returns undefined for a non json content type', async () => {
    stubFetch(makeResponse({ body: 'plain text', contentType: 'text/plain' }));
    await expect(apiFetch('raw')).resolves.toBeUndefined();
  });

  it('returns undefined when the json body cannot be parsed', async () => {
    stubFetch(makeResponse({ body: 'not json' }));
    await expect(apiFetch('broken')).resolves.toBeUndefined();
  });

  it('returns text when the response type is text', async () => {
    stubFetch(makeResponse({ body: 'hello', contentType: 'text/plain' }));
    await expect(apiFetch('raw', { responseType: 'text' })).resolves.toBe('hello');
  });

  it('returns a blob when the response type is blob', async () => {
    const blob = {} as Blob;
    stubFetch(makeResponse({ blob }));
    await expect(apiFetch('file', { responseType: 'blob' })).resolves.toBe(blob);
  });

  it('throws ApprovalPendingError for an approval_pending payload', async () => {
    const approval = { id: 'apr-1', action: 'delete', status: 'approval_pending' };
    stubFetch(makeResponse({ body: JSON.stringify({ ok: true, data: approval }) }));

    const error = await apiFetch('run').catch((err: unknown) => err);
    expect(isApprovalPending(error)).toBe(true);
    expect((error as ApprovalPendingError).approval).toEqual(approval);
    expect((error as ApprovalPendingError).name).toBe('ApprovalPendingError');
    expect((error as ApprovalPendingError).message).toBe('approval_pending');
  });

  it('falls back to the whole payload when the approval field is absent', async () => {
    const approval = { id: 'apr-2', status: 'approval_pending' };
    stubFetch(makeResponse({ body: JSON.stringify({ ok: true, data: approval }) }));

    const error = await apiFetch('run').catch((err: unknown) => err);
    expect(isApprovalPending(error)).toBe(true);
  });

  it('rejects a non approval pending error', () => {
    expect(isApprovalPending(new Error('nope'))).toBe(false);
    expect(isApprovalPending(null)).toBe(false);
  });
});

describe('apiFetch error normalization', () => {
  it('maps http status codes to error codes', async () => {
    const cases: Array<[number, string]> = [
      [400, 'bad_request'],
      [401, 'unauthorized'],
      [403, 'forbidden'],
      [404, 'not_found'],
      [409, 'conflict'],
      [422, 'validation_error'],
      [429, 'rate_limited'],
      [500, 'server_error'],
      [503, 'server_error'],
      [418, 'http_418'],
    ];

    for (const [status, code] of cases) {
      stubFetch(makeResponse({ status, statusText: 'Failure', body: 'nope' }));
      const error = await captureError(apiFetch('x'));
      expect(error).toBeInstanceOf(ApiError);
      expect(error.code).toBe(code);
      expect(error.status).toBe(status);
      expect(error.message).toBe('Failure');
    }
  });

  it('reads code and message from a json error body', async () => {
    stubFetch(
      makeResponse({
        status: 403,
        statusText: 'Forbidden',
        body: JSON.stringify({ error: { code: 'quota_exceeded', message: 'No space left' } }),
      })
    );

    const error = await captureError(apiFetch('x'));
    expect(error.code).toBe('quota_exceeded');
    expect(error.message).toBe('No space left');
    expect(error.status).toBe(403);
  });

  it('unwraps a nested error string and falls back to the http code', async () => {
    stubFetch(
      makeResponse({
        status: 400,
        statusText: 'Bad Request',
        body: JSON.stringify({ error: 'name required' }),
      })
    );

    const error = await captureError(apiFetch('x'));
    expect(error.code).toBe('bad_request');
    expect(error.message).toBe('name required');
    expect(error.status).toBe(400);
  });

  it('normalizes retryAfter from camel case and snake case', async () => {
    stubFetch(
      makeResponse({
        status: 429,
        body: JSON.stringify({
          error: { code: 'rate_limited', message: 'slow down', retryAfter: 12 },
        }),
      })
    );
    const camel = await captureError(apiFetch('x'));
    expect(camel.retryAfter).toBe(12);

    stubFetch(
      makeResponse({
        status: 429,
        body: JSON.stringify({ error: { message: 'slow down', retry_after: 30 } }),
      })
    );
    const snake = await captureError(apiFetch('x'));
    expect(snake.retryAfter).toBe(30);
  });

  it('throws an ApiError for an ok false envelope', async () => {
    stubFetch(
      makeResponse({
        body: JSON.stringify({ ok: false, error: { code: 'not_found', message: 'Missing file' } }),
      })
    );

    const error = await captureError(apiFetch('x'));
    expect(error).toBeInstanceOf(ApiError);
    expect(error.code).toBe('not_found');
    expect(error.message).toBe('Missing file');
    expect(error.status).toBe(200);
  });

  it('uses the response status text when an ok false envelope has no message', async () => {
    stubFetch(makeResponse({ status: 200, statusText: 'No Content', body: '{"ok":false}' }));

    const error = await captureError(apiFetch('x'));
    expect(error.message).toBe('No Content');
    expect(error.code).toBe('http_200');
  });

  it('reads a string error from an ok false envelope', async () => {
    stubFetch(makeResponse({ body: '{"ok":false,"error":"denied"}' }));

    const error = await captureError(apiFetch('x'));
    expect(error.code).toBe('denied');
    expect(error.message).toBe('denied');
    expect(error.status).toBe(200);
  });
});

describe('ApiError', () => {
  it('derives the code from a numeric status', () => {
    const error = new ApiError(500, 'boom');
    expect(error.code).toBe('server_error');
    expect(error.status).toBe(500);
    expect(error.message).toBe('boom');
    expect(error.name).toBe('ApiError');
    expect(error).toBeInstanceOf(Error);
  });

  it('keeps an explicit code and status', () => {
    const error = new ApiError('custom_code', 'nope', 409, { retry_after: 5 });
    expect(error.code).toBe('custom_code');
    expect(error.status).toBe(409);
    expect(error.retryAfter).toBe(5);
  });

  it('ignores a non numeric retry hint', () => {
    const error = new ApiError('custom_code', 'nope', 409, { retryAfter: 'soon' });
    expect(error.retryAfter).toBeUndefined();
  });
});

describe('csrf token storage', () => {
  it('writes both the meta tag and the cookie', () => {
    setCsrfToken('abc 123');
    const meta = document.querySelector('meta[name="csrf-token"]');
    expect(meta?.getAttribute('content')).toBe('abc 123');
    expect(document.cookie).toContain('csrf_token=abc%20123');
  });

  it('reads the token back from the meta tag', () => {
    setCsrfToken('meta-token');
    expect(getCsrfToken()).toBe('meta-token');
  });

  it('falls back to the cookie when the meta tag is empty', () => {
    document.cookie = 'csrf_token=cookie%20token; path=/';
    expect(getCsrfToken()).toBe('cookie token');
  });

  it('returns an empty string when nothing is stored', () => {
    expect(getCsrfToken()).toBe('');
  });

  it('clears the stored token', () => {
    setCsrfToken('gone');
    clearCsrfToken();
    expect(getCsrfToken()).toBe('');
  });

  it('does not create a meta tag when clearing an unset token', () => {
    clearCsrfToken();
    expect(document.querySelector('meta[name="csrf-token"]')).toBeNull();
  });

  it('ignores empty tokens when storing', () => {
    setCsrfToken('');
    expect(getCsrfToken()).toBe('');
  });

  it('is a no-op without a document', () => {
    vi.stubGlobal('document', undefined);
    expect(getCsrfToken()).toBe('');
    expect(() => setCsrfToken('none')).not.toThrow();
    expect(() => clearCsrfToken()).not.toThrow();
  });
});
