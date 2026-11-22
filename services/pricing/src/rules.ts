/**
 * The rules this service exists to own.
 *
 * They live here, in this repository, on this deploy cycle — that is the whole
 * reason pricing is a separate service. Tax rates change when a legislature says
 * so and coupons change when marketing says so; neither should require a deploy
 * of the billing core, and neither should be able to break a renewal if it is
 * wrong.
 *
 * They are in-memory tables rather than a database because a lookup table with
 * a dozen rows and a weekly change rate does not need Postgres, and a service
 * that boots with no external dependency is a service that can be brought up in
 * a test in milliseconds. A production deployment with per-tenant coupon
 * inventory would move `COUPONS` behind a datastore; the shape of `findCoupon`
 * is what would change, not its callers.
 */

/** Currencies the billing core knows about. Mirrors `App\Enums\Currency`. */
export const CURRENCIES = ['BRL', 'USD', 'EUR'] as const;

export type Currency = (typeof CURRENCIES)[number];

export function isCurrency(value: unknown): value is Currency {
  return typeof value === 'string' && (CURRENCIES as readonly string[]).includes(value);
}

/**
 * Rates are basis points (1 bp = 0.01%), so 900 is nine percent.
 *
 * Integers, not floats, for the reason set out in money.ts: 0.09 is not exactly
 * nine percent in binary and a tax engine must start from an exact rate.
 */
export interface TaxRule {
  readonly code: string;
  readonly description: string;
  readonly rateBasisPoints: number;
}

/**
 * Region codes are `COUNTRY` or `COUNTRY-SUBDIVISION`, upper case.
 *
 * Lookup falls back from the subdivision to the country, so an unmapped
 * `BR-AC` still gets Brazil's federal rate rather than silently no tax. A
 * missing country is a zero-rate region, which is a deliberate choice: this
 * service must never invent a tax it cannot name.
 */
const TAX_RULES: Readonly<Record<string, TaxRule>> = {
  BR: { code: 'BR-FED', description: 'Federal service tax', rateBasisPoints: 465 },
  'BR-SP': { code: 'BR-SP-ISS', description: 'ISS São Paulo', rateBasisPoints: 900 },
  'BR-RJ': { code: 'BR-RJ-ISS', description: 'ISS Rio de Janeiro', rateBasisPoints: 500 },
  US: { code: 'US-NONE', description: 'No federal sales tax', rateBasisPoints: 0 },
  'US-CA': { code: 'US-CA-SUT', description: 'California sales and use tax', rateBasisPoints: 725 },
  'US-NY': { code: 'US-NY-ST', description: 'New York state sales tax', rateBasisPoints: 400 },
  PT: { code: 'PT-IVA', description: 'IVA Portugal', rateBasisPoints: 2300 },
  DE: { code: 'DE-UST', description: 'Umsatzsteuer', rateBasisPoints: 1900 },
};

export function findTaxRule(region: string): TaxRule | null {
  const normalised = region.trim().toUpperCase();

  if (Object.hasOwn(TAX_RULES, normalised)) {
    return TAX_RULES[normalised] ?? null;
  }

  const country = normalised.split('-')[0] ?? '';

  return Object.hasOwn(TAX_RULES, country) ? TAX_RULES[country] ?? null : null;
}

/**
 * A coupon is either a percentage or a fixed amount.
 *
 * A fixed-amount coupon is bound to one currency. "R$ 10 off" applied to a USD
 * plan is not "$ 10 off" — it is a bug that hands a customer a 5x discount, so
 * the currency is part of the rule and mismatches are rejected rather than
 * converted.
 */
export type Coupon =
  | {
      readonly code: string;
      readonly description: string;
      readonly kind: 'percentage';
      readonly rateBasisPoints: number;
    }
  | {
      readonly code: string;
      readonly description: string;
      readonly kind: 'fixed';
      readonly amountMinorUnits: number;
      readonly currency: Currency;
    };

const COUPONS: Readonly<Record<string, Coupon>> = {
  WELCOME10: {
    code: 'WELCOME10',
    description: '10% off',
    kind: 'percentage',
    rateBasisPoints: 1000,
  },
  HALFOFF: {
    code: 'HALFOFF',
    description: '50% off',
    kind: 'percentage',
    rateBasisPoints: 5000,
  },
  BRL20: {
    code: 'BRL20',
    description: 'R$ 20,00 off',
    kind: 'fixed',
    amountMinorUnits: 2000,
    currency: 'BRL',
  },
  USD5: {
    code: 'USD5',
    description: 'US$ 5.00 off',
    kind: 'fixed',
    amountMinorUnits: 500,
    currency: 'USD',
  },
};

export function findCoupon(code: string): Coupon | null {
  const normalised = code.trim().toUpperCase();
  return Object.hasOwn(COUPONS, normalised) ? COUPONS[normalised] ?? null : null;
}
