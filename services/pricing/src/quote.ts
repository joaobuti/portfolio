import { applyRate, clamp } from './money.js';
import { type Coupon, type Currency, findCoupon, findTaxRule } from './rules.js';

/** Basis points in one whole. 10000 bp = 100%. */
const BASIS_POINTS = 10_000;

export interface QuoteRequest {
  readonly amountMinorUnits: number;
  readonly currency: Currency;
  readonly region: string;
  readonly couponCode?: string;
}

export interface QuoteLine {
  readonly type: 'coupon' | 'tax';
  readonly code: string;
  readonly description: string;
  /** Signed against the subtotal: negative discounts, positive taxes. */
  readonly amountMinorUnits: number;
}

export type CouponRejection = 'unknown' | 'currency_mismatch' | 'not_applicable';

export interface CouponOutcome {
  readonly code: string;
  readonly applied: boolean;
  readonly reason?: CouponRejection;
}

export interface Quote {
  readonly currency: Currency;
  readonly subtotalMinorUnits: number;
  /** Positive magnitude of the discount, zero when no coupon applied. */
  readonly discountMinorUnits: number;
  readonly taxableMinorUnits: number;
  readonly taxMinorUnits: number;
  readonly totalMinorUnits: number;
  readonly lines: readonly QuoteLine[];
  readonly coupon: CouponOutcome | null;
  readonly taxRegion: string | null;
}

/**
 * Price an amount: discount first, then tax on what remains.
 *
 * ## The order matters and is a policy, not an accident
 *
 * Tax is computed on the discounted amount, not the list price. That is the
 * common rule (the customer is taxed on what they actually pay) and it is
 * stated here once so no call site can assume the other order. Reversing it
 * changes every total in the system.
 *
 * ## Negative subtotals are legitimate
 *
 * A mid-cycle downgrade produces a credit, and the billing core sends it here
 * as a negative amount. Tax is applied symmetrically — refunding the service
 * without refunding its tax would quietly keep money that is not ours. Coupons,
 * on the other hand, are not applied to credits: "10% off" a refund would
 * shrink what we owe the customer, which is the wrong direction.
 *
 * This function is pure. No clock, no I/O, no config — the same inputs always
 * produce the same quote, which is what lets the whole pricing surface be
 * tested without starting a server.
 */
export function quote(request: QuoteRequest): Quote {
  const subtotal = request.amountMinorUnits;
  const lines: QuoteLine[] = [];

  const { discount, outcome } = resolveDiscount(subtotal, request);

  if (outcome?.applied && discount > 0) {
    const coupon = findCoupon(request.couponCode ?? '');

    lines.push({
      type: 'coupon',
      code: coupon?.code ?? 'COUPON',
      description: coupon?.description ?? 'Discount',
      amountMinorUnits: -discount,
    });
  }

  const taxable = subtotal - discount;
  const taxRule = findTaxRule(request.region);
  const tax = taxRule ? applyRate(taxable, taxRule.rateBasisPoints, BASIS_POINTS) : 0;

  if (taxRule && tax !== 0) {
    lines.push({
      type: 'tax',
      code: taxRule.code,
      description: taxRule.description,
      amountMinorUnits: tax,
    });
  }

  return {
    currency: request.currency,
    subtotalMinorUnits: subtotal,
    discountMinorUnits: discount,
    taxableMinorUnits: taxable,
    taxMinorUnits: tax,
    totalMinorUnits: taxable + tax,
    lines,
    coupon: outcome,
    taxRegion: taxRule?.code ?? null,
  };
}

function resolveDiscount(
  subtotal: number,
  request: QuoteRequest,
): { discount: number; outcome: CouponOutcome | null } {
  const code = request.couponCode?.trim();

  if (!code) {
    return { discount: 0, outcome: null };
  }

  const coupon = findCoupon(code);

  if (!coupon) {
    return { discount: 0, outcome: { code, applied: false, reason: 'unknown' } };
  }

  // A credit or a zero amount has nothing to discount. Reported rather than
  // silently ignored, so the caller can tell "no discount" from "not asked for".
  if (subtotal <= 0) {
    return {
      discount: 0,
      outcome: { code: coupon.code, applied: false, reason: 'not_applicable' },
    };
  }

  if (coupon.kind === 'fixed' && coupon.currency !== request.currency) {
    return {
      discount: 0,
      outcome: { code: coupon.code, applied: false, reason: 'currency_mismatch' },
    };
  }

  return {
    discount: clamp(rawDiscount(coupon, subtotal), 0, subtotal),
    outcome: { code: coupon.code, applied: true },
  };
}

function rawDiscount(coupon: Coupon, subtotal: number): number {
  return coupon.kind === 'percentage'
    ? applyRate(subtotal, coupon.rateBasisPoints, BASIS_POINTS)
    : coupon.amountMinorUnits;
}
