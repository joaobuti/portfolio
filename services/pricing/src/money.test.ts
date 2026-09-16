import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { AmountOverflowError, applyRate, clamp } from './money.js';

/**
 * These are not generic rounding tests. The cases below are transcribed from
 * `tests/Unit/Support/MoneyTest.php` in the billing core, because the only
 * property that matters here is that the two implementations agree. If this
 * file and that one ever disagree, a customer sees one number in the preview
 * and is charged another.
 */
describe('parity with App\\Support\\Money::prorate', () => {
  it('rounds half away from zero for positive amounts', () => {
    // PHP: Money::ofMinor(100)->prorate(1, 8) === 13   (12.5 -> 13)
    assert.equal(applyRate(100, 1, 8), 13);
  });

  it('rounds half away from zero for negative amounts', () => {
    // PHP: Money::ofMinor(-100)->prorate(1, 8) === -13 (-12.5 -> -13)
    assert.equal(applyRate(-100, 1, 8), -13);
  });

  it('is the identity for a full period', () => {
    assert.equal(applyRate(9990, 30, 30), 9990);
  });

  it('is zero for a zero numerator', () => {
    assert.equal(applyRate(9990, 0, 30), 0);
  });

  it('refuses a zero denominator instead of dividing by it', () => {
    assert.throws(() => applyRate(100, 1, 0), RangeError);
  });
});

describe('applyRate', () => {
  it('applies a basis-point rate exactly', () => {
    // 9% of R$ 49,90 is R$ 4,491 -> 449 cents (4.491 rounds down).
    assert.equal(applyRate(4990, 900, 10_000), 449);
  });

  it('rounds a tax that lands exactly on the half up', () => {
    // 50 * 1/4 = 12.5 -> 13
    assert.equal(applyRate(50, 1, 4), 13);
  });

  it('rounds below the half down', () => {
    // 49 * 1/4 = 12.25 -> 12
    assert.equal(applyRate(49, 1, 4), 12);
  });

  it('stays exact past the float53 boundary', () => {
    // The intermediate product here is 9_007_199_254_740_990_000, which a
    // Number cannot hold. BigInt can, and the quotient is back in range.
    assert.equal(applyRate(900_719_925_474_099, 10_000, 10_000), 900_719_925_474_099);
  });

  it('reports an overflow rather than returning a wrong number', () => {
    assert.throws(() => applyRate(Number.MAX_SAFE_INTEGER, 10, 1), AmountOverflowError);
  });

  it('rejects a non-integer amount', () => {
    assert.throws(() => applyRate(49.9, 1, 2), TypeError);
  });
});

describe('clamp', () => {
  it('bounds a value into the range', () => {
    assert.equal(clamp(150, 0, 100), 100);
    assert.equal(clamp(-5, 0, 100), 0);
    assert.equal(clamp(42, 0, 100), 42);
  });
});
