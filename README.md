# silvershop-suite/address-lookup-postnl

A **satellite provider** for [`silvershop-suite/address-lookup`](https://github.com/silvershop/silvershop-address-lookup)
(CMS 6) that resolves addresses through **PostNL's Adrescheck Autocomplete (ACI) API** — the carrier-aligned option
for shops that already ship with PostNL (or MyParcel).

It registers one provider (`postnl`) in the base module's per-country registry. PostNL's unified autocomplete endpoint
serves **both** the structured Dutch postcode + house-number lookup *and* free-text international autocomplete, so a
single connector covers NL precisely and the rest of the world as typeahead.

## Capabilities

PostNL exposes one endpoint — `GET https://api.postnl.nl/v1/address/autocomplete/search` — authenticated with an
**`apikey` request header**. There is **no** autocomplete→retrieve two-step: `/search` returns full addresses
directly. The only optional first step is `/token` for a session `uuid`; this provider **self-generates** that uuid
(RFC 4122 v4) and skips the extra round-trip.

| Country | Mode | Request | Resolves |
| --- | --- | --- | --- |
| **NL** | `postcode_number` | `/search?countryIso=NL&postalCode=&houseNumber=(&houseNumberAddition=)` | full structured address: **street + city + region** |
| **every other country** | `autocomplete` | `/search?countryIso=&q=(&postalCode=)` | candidate addresses from a free-text term |

| Method | Behaviour |
| --- | --- |
| `lookup(AddressQuery)` | **NL:** structured postcode + house number → one `ResolvedAddress` (picks the typed house-number addition when several exist). **Other:** free-text `q` (+ `postalCode` filter) → the first candidate |
| `suggest(term, iso)` | free-text `/search` → candidate `ResolvedAddress` rows, capped to `result_limit` (ACI has no limit param, so the cap is client-side) |
| `validate(ResolvedAddress)` | a best-effort **lookup-match** (ACI has no boolean verify endpoint): a hit returns the resolved address as the canonical form (score `1.0`); a miss or any fault **soft-passes** |

**Coverage.** ACI advertises *"all countries of the world"* and exposes no country-enumeration endpoint; the actual
per-account entitlement is enforced server-side (HTTP `403` for an unsupported entry-point country, which degrades to
a soft miss). The provider therefore accepts any well-formed ISO 3166-1 alpha-2 code and lets a genuine miss fall back
to the manual form.

> **Field mapping.** ACI returns `streetName` → street, `houseNumber` (a JSON number) → house number,
> `houseNumberAddition` → suffix, `postalCode`, `cityName` → city, `region` → state, lowercase `countryIso` →
> upper-cased country code. ACI returns **no coordinates**, so `lat`/`lng` stay null.

## Installation

```bash
composer require silvershop-suite/address-lookup-postnl
```

Then run `dev/build?flush=all`.

### API key

The provider authenticates with an **`apikey` header** and reads the key from the environment variable
**`SS_POSTNL_API_KEY`**:

```dotenv
# .env
SS_POSTNL_API_KEY="your-postnl-api-key"
```

Without a key the provider is **inert**: `isConfigured()` is false, the registry skips it, and the checkout form stays
manual — so the module is safe to install before credentials are supplied. The key is sent only in the server-side
request header; it is **never** put in a URL/query string or exposed to the browser.

## Configuration

Registration is applied automatically by `_config/address-lookup-postnl.yml`, which **only registers the provider as
available** — it deliberately does **not** set `by_country` or `default`, so the base defaults (`NL → pdok`,
`DE → openplz`, `BE → myparcel`) and any other installed satellite's routing (e.g. the Loqate satellite's global
`default`) are left untouched.

```yaml
SilverShop\AddressLookup\Provider\AddressLookupProviderRegistry:
  providers:
    postnl: '%$SilverShop\AddressLookup\PostNL\PostNLAddressProvider'
```

Opt PostNL in where you want it:

```yaml
# Carrier-aligned NL: use PostNL for the Dutch postcode + house-number lookup instead of the free government PDOK.
SilverShop\AddressLookup\Provider\AddressLookupProviderRegistry:
  by_country:
    NL: postnl
```

```yaml
# Or route specific international countries to PostNL's autocomplete.
SilverShop\AddressLookup\Provider\AddressLookupProviderRegistry:
  by_country:
    GB: postnl
    FR: postnl
```

```yaml
# Or prefer PostNL globally (becomes the fallback for every country without a by_country entry).
SilverShop\AddressLookup\Provider\AddressLookupProviderRegistry:
  default: postnl
```

### Provider options

```yaml
SilverShop\AddressLookup\PostNL\PostNLAddressProvider:
  endpoint: 'https://api.postnl.nl/v1/address/autocomplete'  # override to pin a sandbox / mirror host
  api_key_env: 'SS_POSTNL_API_KEY'                           # the env var holding the key
  result_limit: 10                                           # max suggest candidates (trimmed client-side)
  timeout: 5                                                 # upstream timeout, seconds (inherited from the HTTP base)
```

## Consent

PostNL is an **EU carrier** used to fulfil the order (own-logistics-adjacent), so the provider is
**functional/necessary** and declares **no consent category** — it runs without a cookie gate (same posture as the
base module's bundled providers and the MyParcel satellite, and unlike the consent-gated global Loqate satellite).

## Hardening

Inherited from the base module's `AbstractHttpProvider`: **SSL verify ON**, a short upstream timeout, and a graceful
empty answer on any miss or fault, so a slow or failing address API never blocks checkout. The lookup endpoint itself
(POST-only, same-origin + CSRF, rate-limited, PII in the request body not the URL) lives in the base module.

## Requirements

- PHP ^8.3
- silverstripe/framework ^6
- silvershop/core ^6
- silvershop-suite/address-lookup ^1
- A PostNL API key (`SS_POSTNL_API_KEY`)

## Pairs well with

- [`silvershop-suite/shipping-myparcel`](https://github.com/silvershop) — a shop shipping NL/BE parcels via PostNL/MyParcel
  gets carrier-aligned address data from the same courier.

## License

BSD-3-Clause.
