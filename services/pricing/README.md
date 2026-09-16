# Pricing service

Regional tax and coupon rules for the billing core, on their own deploy cycle.

A legislature changes a rate; marketing launches a coupon. Neither should
require a deploy of the code that renews subscriptions — that separation is the
entire reason this is a service and not a class.

## Design in one paragraph

One pure function (`quote`) behind two HTTP routes, with **zero runtime
dependencies**. Node's own `http` module covers a health check and a POST;
Express would be a supply-chain surface taken on for nothing in a service that
sits in the path of every plan change. The production image is the base image
plus a few kilobytes of compiled JavaScript.

## The contract

```
POST /v1/quote
{
  "amountMinorUnits": 4990,     // integer, signed; negative is a credit
  "currency": "BRL",            // BRL | USD | EUR
  "region": "BR-SP",            // COUNTRY or COUNTRY-SUBDIVISION
  "couponCode": "WELCOME10"     // optional
}

200
{
  "currency": "BRL",
  "subtotalMinorUnits": 4990,
  "discountMinorUnits": 499,
  "taxableMinorUnits": 4491,
  "taxMinorUnits": 404,
  "totalMinorUnits": 4895,
  "lines": [
    { "type": "coupon", "code": "WELCOME10", "description": "10% off", "amountMinorUnits": -499 },
    { "type": "tax", "code": "BR-SP-ISS", "description": "ISS São Paulo", "amountMinorUnits": 404 }
  ],
  "coupon": { "code": "WELCOME10", "applied": true },
  "taxRegion": "BR-SP-ISS"
}
```

`GET /health` returns `{"status":"ok"}` while the process is answering.

### Three invariants the caller may rely on

1. **`subtotal - discount + tax === total`**, always. The Laravel client
   asserts this on every response and refuses the quote if it fails.
2. **Every amount is an integer in the currency's minor unit.** Never a float.
   A float in a request is a 422, not a rounding.
3. **Rounding is half-up away from zero**, identical to PHP's
   `Money::prorate()`. The PHP test cases are transcribed into
   `src/money.test.ts` — if the two ever disagree, a preview disagrees with a
   charge.

### Errors

Same envelope as the billing core's `DomainException`:

```json
{ "error": { "code": "invalid_request", "message": "...", "context": {} } }
```

| Status | When |
| --- | --- |
| 400 | body is not JSON |
| 404 | unknown route |
| 413 | body over 8KB |
| 422 | body is JSON but not a valid request |
| 500 | a bug in this service |

A **rejected coupon is a 200**, with `coupon.applied: false` and a reason. A
typo must not look like a failing dependency, or a customer guessing codes
opens the client's circuit breaker for everyone.

## Running it

```sh
npm install
npm run check      # typecheck + tests
npm run build && npm start
```

Requires Node 22. From the repository root, `docker compose up pricing` builds
and runs it on `localhost:3001`.

## Where the rules live

`src/rules.ts`, as in-memory tables. A lookup table with a dozen rows and a
weekly change rate does not need Postgres, and a service that boots with no
external dependency can be started inside a test in milliseconds. Per-tenant
coupon inventory would move `findCoupon` behind a datastore — its callers would
not change.

Tax lookup falls back from the subdivision to the country (`BR-AC` → `BR`), and
an unmapped country is zero-rated. This service never invents a tax it cannot
name.

## Failure behaviour is the client's business

This service has no retry logic, no fallback and no cache. The billing core
owns all of that — timeout, circuit breaker and a degraded quote — because only
the caller knows whether it is drawing a preview or moving money. See
[ADR-0005](../../docs/adr/0005-synchronous-pricing-with-a-degraded-fallback.md).
