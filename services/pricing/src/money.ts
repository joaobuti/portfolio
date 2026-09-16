/**
 * Integer money arithmetic, mirroring the PHP `App\Support\Money` exactly.
 *
 * This file is the reason the service can be trusted. The Laravel side computes
 * proration and this service computes tax and discounts; if the two round
 * differently, a preview disagrees with the charge and the customer sees one
 * number and pays another. That bug is invisible in testing and expensive in
 * production, so the rounding rule is transcribed here deliberately rather than
 * reimplemented from memory:
 *
 *   PHP: $q = intdiv($scaled, $d); $r = $scaled % $d;
 *        if (abs($r) * 2 >= abs($d)) { $q += $scaled < 0 ? -1 : 1; }
 *
 * That is half-up *away from zero* — 2.5 rounds to 3, -2.5 rounds to -3 — which
 * is what payment gateways and accountants expect. See ADR-0002.
 *
 * The arithmetic runs in BigInt because `amount * numerator` overflows the
 * float53 safe-integer range far sooner than people expect: a R$ 50.000,00
 * invoice in cents multiplied by a basis-point rate is already 5e10, and the
 * intermediate products compound from there. BigInt is exact; Number is not.
 */

/** Thrown when a computed amount cannot be represented as a safe integer. */
export class AmountOverflowError extends Error {
  constructor(value: bigint) {
    super(`Computed amount [${value}] exceeds the safe integer range.`);
    this.name = 'AmountOverflowError';
  }
}

/**
 * Multiply a minor-unit amount by the rational numerator/denominator, rounding
 * half-up away from zero.
 *
 * Rates are passed as integer pairs rather than a float on purpose. `0.09` is
 * not exactly nine percent in binary floating point, and a tax engine that
 * starts from an inexact rate cannot produce an exact cent.
 */
export function applyRate(
  minorUnits: number,
  numerator: number,
  denominator: number,
): number {
  if (!Number.isSafeInteger(minorUnits)) {
    throw new TypeError(`Amount [${minorUnits}] must be a safe integer.`);
  }
  if (!Number.isSafeInteger(numerator) || !Number.isSafeInteger(denominator)) {
    throw new TypeError('Rate numerator and denominator must be safe integers.');
  }
  if (denominator === 0) {
    throw new RangeError('Cannot apply a rate with a zero denominator.');
  }

  const scaled = BigInt(minorUnits) * BigInt(numerator);
  const d = BigInt(denominator);

  // BigInt division truncates toward zero, matching PHP's intdiv().
  let quotient = scaled / d;
  const remainder = scaled % d;

  if (abs(remainder) * 2n >= abs(d)) {
    quotient += scaled < 0n ? -1n : 1n;
  }

  return toSafeNumber(quotient);
}

/** Clamp a value into [min, max]. Used to stop a discount exceeding a subtotal. */
export function clamp(value: number, min: number, max: number): number {
  return Math.max(min, Math.min(max, value));
}

function abs(value: bigint): bigint {
  return value < 0n ? -value : value;
}

function toSafeNumber(value: bigint): number {
  const asNumber = Number(value);

  if (!Number.isSafeInteger(asNumber)) {
    throw new AmountOverflowError(value);
  }

  return asNumber;
}
