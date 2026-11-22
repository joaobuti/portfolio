import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { quote } from './quote.js';
import { AmountOverflowError } from './money.js';

it('rejects positive and negative totals outside the safe integer range', () => {
  for (const amountMinorUnits of [Number.MAX_SAFE_INTEGER - 1, -(Number.MAX_SAFE_INTEGER - 1)]) {
    assert.throws(() => quote({ amountMinorUnits, currency: 'BRL', region: 'BR-SP' }), AmountOverflowError);
  }
});

it('does not interpret Object prototype properties as coupons or tax rules', () => {
  const result = quote({ amountMinorUnits: 1000, currency: 'BRL', region: '__proto__', couponCode: '__proto__' });
  assert.equal(result.totalMinorUnits, 1000);
  assert.equal(result.coupon?.applied, false);
  assert.equal(result.taxRegion, null);
});

describe('tax', () => {
  it('applies the subdivision rate when one is mapped', () => {
    const result = quote({ amountMinorUnits: 4990, currency: 'BRL', region: 'BR-SP' });

    assert.equal(result.taxMinorUnits, 449); // 9% of 4990, half-up
    assert.equal(result.totalMinorUnits, 5439);
    assert.equal(result.taxRegion, 'BR-SP-ISS');
  });

  it('falls back to the country rate for an unmapped subdivision', () => {
    const result = quote({ amountMinorUnits: 10_000, currency: 'BRL', region: 'BR-AC' });

    assert.equal(result.taxRegion, 'BR-FED');
    assert.equal(result.taxMinorUnits, 465); // 4.65%
  });

  it('charges nothing for an unknown region rather than inventing a rate', () => {
    const result = quote({ amountMinorUnits: 10_000, currency: 'EUR', region: 'ZZ' });

    assert.equal(result.taxMinorUnits, 0);
    assert.equal(result.taxRegion, null);
    assert.equal(result.totalMinorUnits, 10_000);
  });

  it('is case and whitespace insensitive about the region', () => {
    const result = quote({ amountMinorUnits: 10_000, currency: 'BRL', region: '  br-sp ' });

    assert.equal(result.taxRegion, 'BR-SP-ISS');
  });
});

describe('coupons', () => {
  it('applies a percentage coupon before tax', () => {
    const result = quote({
      amountMinorUnits: 10_000,
      currency: 'BRL',
      region: 'BR-SP',
      couponCode: 'WELCOME10',
    });

    assert.equal(result.discountMinorUnits, 1000);
    assert.equal(result.taxableMinorUnits, 9000);
    assert.equal(result.taxMinorUnits, 810); // 9% of 9000, not of 10000
    assert.equal(result.totalMinorUnits, 9810);
  });

  it('applies a fixed coupon in the matching currency', () => {
    const result = quote({
      amountMinorUnits: 10_000,
      currency: 'BRL',
      region: 'ZZ',
      couponCode: 'BRL20',
    });

    assert.equal(result.discountMinorUnits, 2000);
    assert.equal(result.totalMinorUnits, 8000);
  });

  it('refuses a fixed coupon from another currency instead of converting it', () => {
    const result = quote({
      amountMinorUnits: 10_000,
      currency: 'USD',
      region: 'ZZ',
      couponCode: 'BRL20',
    });

    assert.equal(result.discountMinorUnits, 0);
    assert.deepEqual(result.coupon, {
      code: 'BRL20',
      applied: false,
      reason: 'currency_mismatch',
    });
  });

  it('reports an unknown coupon without failing the quote', () => {
    const result = quote({
      amountMinorUnits: 10_000,
      currency: 'BRL',
      region: 'ZZ',
      couponCode: 'NOPE',
    });

    // The quote still stands at full price. A typo must not read as a service
    // failure, or a customer guessing coupon codes trips the circuit breaker.
    assert.equal(result.totalMinorUnits, 10_000);
    assert.deepEqual(result.coupon, { code: 'NOPE', applied: false, reason: 'unknown' });
  });

  it('never discounts past zero', () => {
    const result = quote({
      amountMinorUnits: 1000,
      currency: 'BRL',
      region: 'ZZ',
      couponCode: 'BRL20', // R$ 20,00 off a R$ 10,00 amount
    });

    assert.equal(result.discountMinorUnits, 1000);
    assert.equal(result.totalMinorUnits, 0);
  });

  it('reports no coupon at all as null, not as a rejection', () => {
    const result = quote({ amountMinorUnits: 1000, currency: 'BRL', region: 'ZZ' });

    assert.equal(result.coupon, null);
  });
});

describe('credits', () => {
  it('taxes a negative amount symmetrically', () => {
    // A downgrade credit refunds the tax with the service. Keeping the tax on
    // money we are giving back would be quietly keeping money that is not ours.
    const result = quote({ amountMinorUnits: -10_000, currency: 'BRL', region: 'BR-SP' });

    assert.equal(result.taxMinorUnits, -900);
    assert.equal(result.totalMinorUnits, -10_900);
  });

  it('does not apply a coupon to a credit', () => {
    const result = quote({
      amountMinorUnits: -10_000,
      currency: 'BRL',
      region: 'ZZ',
      couponCode: 'WELCOME10',
    });

    assert.equal(result.discountMinorUnits, 0);
    assert.deepEqual(result.coupon, {
      code: 'WELCOME10',
      applied: false,
      reason: 'not_applicable',
    });
  });
});

describe('lines', () => {
  it('signs discounts negative and taxes positive', () => {
    const result = quote({
      amountMinorUnits: 10_000,
      currency: 'BRL',
      region: 'BR-SP',
      couponCode: 'WELCOME10',
    });

    assert.deepEqual(
      result.lines.map((line) => [line.type, line.amountMinorUnits]),
      [
        ['coupon', -1000],
        ['tax', 810],
      ],
    );
  });

  it('omits a zero tax line', () => {
    const result = quote({ amountMinorUnits: 10_000, currency: 'USD', region: 'US' });

    assert.deepEqual(result.lines, []);
  });

  it('always reconciles: subtotal - discount + tax === total', () => {
    const cases = [
      { amountMinorUnits: 4990, currency: 'BRL', region: 'BR-SP', couponCode: 'WELCOME10' },
      { amountMinorUnits: 19_990, currency: 'USD', region: 'US-CA', couponCode: 'USD5' },
      { amountMinorUnits: -3333, currency: 'EUR', region: 'DE' },
      { amountMinorUnits: 1, currency: 'EUR', region: 'PT', couponCode: 'HALFOFF' },
    ] as const;

    for (const input of cases) {
      const result = quote(input);

      assert.equal(
        result.subtotalMinorUnits - result.discountMinorUnits + result.taxMinorUnits,
        result.totalMinorUnits,
        `failed to reconcile for ${JSON.stringify(input)}`,
      );
    }
  });
});
