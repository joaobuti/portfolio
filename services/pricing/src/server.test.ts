import assert from 'node:assert/strict';
import { type AddressInfo } from 'node:net';
import { after, before, describe, it } from 'node:test';

import { createApp } from './server.js';

/**
 * Exercises the real HTTP surface on an ephemeral port. The pure pricing rules
 * are covered in quote.test.ts; what is under test here is the envelope — the
 * status codes and error shapes the Laravel client branches on.
 */

const server = createApp();
let baseUrl = '';

before(async () => {
  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  baseUrl = `http://127.0.0.1:${(server.address() as AddressInfo).port}`;
});

after(async () => {
  await new Promise<void>((resolve, reject) =>
    server.close((error) => (error ? reject(error) : resolve())),
  );
});

async function postQuote(body: unknown, raw?: string) {
  const response = await fetch(`${baseUrl}/v1/quote`, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: raw ?? JSON.stringify(body),
  });

  return { status: response.status, body: await response.json() };
}

describe('GET /health', () => {
  it('answers 200 while the process is up', async () => {
    const response = await fetch(`${baseUrl}/health`);

    assert.equal(response.status, 200);
    assert.deepEqual(await response.json(), { status: 'ok' });
  });
});

describe('POST /v1/quote', () => {
  it('prices a valid request', async () => {
    const { status, body } = await postQuote({
      amountMinorUnits: 4990,
      currency: 'BRL',
      region: 'BR-SP',
    });

    assert.equal(status, 200);
    assert.equal((body as { totalMinorUnits: number }).totalMinorUnits, 5439);
  });

  it('rejects a float amount with 422 and a machine-readable code', async () => {
    const { status, body } = await postQuote({
      amountMinorUnits: 49.9,
      currency: 'BRL',
      region: 'BR-SP',
    });

    assert.equal(status, 422);
    assert.equal((body as { error: { code: string } }).error.code, 'invalid_request');
  });

  it('rejects an unsupported currency', async () => {
    const { status, body } = await postQuote({
      amountMinorUnits: 100,
      currency: 'GBP',
      region: 'PT',
    });

    assert.equal(status, 422);
    assert.deepEqual(
      (body as { error: { context: { field: string } } }).error.context.field,
      'currency',
    );
  });

  it('rejects a body that is not JSON', async () => {
    const { status, body } = await postQuote(undefined, 'not json at all');

    assert.equal(status, 400);
    assert.equal((body as { error: { code: string } }).error.code, 'invalid_json');
  });

  it('rejects an oversized body instead of buffering it', async () => {
    const response = await fetch(`${baseUrl}/v1/quote`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ region: 'x'.repeat(20_000) }),
    });

    assert.equal(response.status, 413);
    assert.equal((await response.json() as {error: {code: string}}).error.code, 'payload_too_large');
  });

  it('rejects an unsafe computed total through the documented 422 envelope', async () => {
    const {status, body} = await postQuote({ amountMinorUnits: Number.MAX_SAFE_INTEGER - 1, currency: 'BRL', region: 'BR-SP' });
    assert.equal(status, 422);
    assert.equal((body as {error: {code: string}}).error.code, 'unprocessable_amount');
  });
});

describe('unknown routes', () => {
  it('answers 404 in the same error envelope', async () => {
    const response = await fetch(`${baseUrl}/nope`);
    const body = await response.json();

    assert.equal(response.status, 404);
    assert.equal((body as { error: { code: string } }).error.code, 'not_found');
  });
});
