import { type QuoteRequest } from './quote.js';
import { CURRENCIES, isCurrency } from './rules.js';

/**
 * The wire contract, validated by hand.
 *
 * No schema library. The request has four fields and the validation rules fit
 * on one screen; a dependency that turns four `typeof` checks into a decorator
 * grammar is a supply-chain risk taken for nothing, and this service sits in
 * the path of every plan change.
 *
 * The error envelope deliberately mirrors the billing core's
 * `App\Exceptions\DomainException`: a stable machine-readable `code`, a human
 * `message`, and a `context` payload. Two services returning errors in one
 * shape means one error-handling path in the client.
 */

export interface ApiError {
  readonly error: {
    readonly code: string;
    readonly message: string;
    readonly context?: Record<string, unknown>;
  };
}

export type ParseResult =
  | { readonly ok: true; readonly value: QuoteRequest }
  | { readonly ok: false; readonly error: ApiError };

/** Longest coupon code we will even look at, to bound the work an attacker can ask for. */
const MAX_COUPON_LENGTH = 64;
const MAX_REGION_LENGTH = 16;

export function parseQuoteRequest(body: unknown): ParseResult {
  if (typeof body !== 'object' || body === null || Array.isArray(body)) {
    return invalid('The request body must be a JSON object.');
  }

  const payload = body as Record<string, unknown>;
  const amount = payload.amountMinorUnits;
  const currency = payload.currency;
  const region = payload.region;
  const couponCode = payload.couponCode;

  // Integer, not float. A caller that sends 49.9 has already lost a cent
  // somewhere upstream, and accepting it here would launder the bug into a
  // total that looks plausible.
  if (typeof amount !== 'number' || !Number.isSafeInteger(amount)) {
    return invalid('[amountMinorUnits] must be an integer in the currency minor unit.', {
      field: 'amountMinorUnits',
    });
  }

  if (!isCurrency(currency)) {
    return invalid('[currency] must be one of the supported currencies.', {
      field: 'currency',
      supported: CURRENCIES,
    });
  }

  if (typeof region !== 'string' || region.trim() === '') {
    return invalid('[region] is required.', { field: 'region' });
  }

  if (region.length > MAX_REGION_LENGTH) {
    return invalid('[region] is too long.', { field: 'region', max: MAX_REGION_LENGTH });
  }

  if (couponCode !== undefined && couponCode !== null) {
    if (typeof couponCode !== 'string') {
      return invalid('[couponCode] must be a string when present.', { field: 'couponCode' });
    }

    if (couponCode.length > MAX_COUPON_LENGTH) {
      return invalid('[couponCode] is too long.', {
        field: 'couponCode',
        max: MAX_COUPON_LENGTH,
      });
    }
  }

  return {
    ok: true,
    value: {
      amountMinorUnits: amount,
      currency,
      region,
      ...(typeof couponCode === 'string' && couponCode.trim() !== ''
        ? { couponCode }
        : {}),
    },
  };
}

function invalid(message: string, context?: Record<string, unknown>): ParseResult {
  return {
    ok: false,
    error: {
      error: {
        code: 'invalid_request',
        message,
        ...(context ? { context } : {}),
      },
    },
  };
}
