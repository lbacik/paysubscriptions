# Does the current generator produce a usable ts SDK from the API v1 contract?

Research for [#269](https://github.com/lbacik/paysubscriptions/issues/269) (map [#268](https://github.com/lbacik/paysubscriptions/issues/268)). State as of 2026-10-24.

## Answer

**No, not unchanged.** The pinned toolchain cannot parse the contract. `resources/openapi/v1.json` declares `openapi: 3.2.0`, and openapi-generator 7.21.0 rejects it: it reads the document as Swagger 2.0 and reports 125 validation errors. With `--skip-validate-spec` it fails with a `NullPointerException`, and 7.26.0 (the latest release) fails the same way. No SDK is produced.

Two routes do produce a client that compiles: API Platform's built-in 3.0.0 downgrade, or the same document relabelled `3.1.0`. Even then, the client is only usable for single-resource GETs, and only when the caller passes `accept: "application/json"` and prefixes the token with `Bearer `. The HAL responses (collections, POST, PATCH) are modelled from HAL schemas that do not match what the server sends. Relations and pagination links are lost, and embedded items are not deserialized.

## Method and inputs

- Contract: `resources/openapi/v1.json` at `origin/main` `a83c455`. It is identical to the worktree copy and byte-identical to a fresh `bin/console api:openapi:export`.
- Generator: [`lbacik/jsonhub-sdk-generator`](https://github.com/lbacik/jsonhub-sdk-generator) at [`86a2bd8`](https://github.com/lbacik/jsonhub-sdk-generator/tree/86a2bd890d67373bf4975dcdb37b663a3e5173a7). Run as `npm run generate -- --url <local http.server> --target ts --package-name paysubscriptions-sdk`. The pinned `openapi-generator-cli` is 7.21.0 (`openapitools.json`).
- Three inputs were generated:
  1. the contract unchanged (3.2.0);
  2. the contract with only `openapi` relabelled to `3.1.0`;
  3. `bin/console api:openapi:export --spec-version=3.0.0`. This is API Platform's `LegacyOpenApiNormalizer`, which turns `type: [T, "null"]` into `nullable: true`. The served route exposes the same option as `/api/docs.jsonopenapi?spec_version=3.0.0` (`vendor/api-platform/core/src/Symfony/Action/DocumentationAction.php:76`).
- Inputs 2 and 3 produce the same Canonical Client Spec, apart from nullability encoding. Both generated clients pass `tsc` with the generated `tsconfig.json`.
- A strict-mode TypeScript probe was type-checked against both outputs. It covers configuration, list paging, merge-patch, and error handling, and each of its `@ts-expect-error` lines held. A runtime probe used a fake `fetchApi` that returned the HAL shapes asserted in `tests/Api/V1`. Output from that probe is quoted below.
- Server behaviour comes from `tests/Api/V1/*`, `config/packages/api_platform.yaml`, `src/ApiResource/Subscription.php`, ADR 0007 and the vendored API Platform 5.0.5 source. Production was not called.

## Answers per question

### OpenAPI 3.1 nullable type arrays and `required`

- **3.2.0 (unchanged): generation fails.** The log reads `attribute openapi is unexpected`, plus 124 Swagger-2.0-style errors such as `paths.'/api/v1/subscriptions'(post).[Accept].type is missing`. API Platform 5.0 hardcodes `OpenApi::VERSION = '3.2.0'` (`vendor/api-platform/core/src/OpenApi/OpenApi.php:26`).
- **3.1 relabel:** `type: ["string","null"]` renders as `T | null`. The members in `required` (`name`, `billingCycle`, `amount`, `nextPayment` on `Subscription`) stay non-optional (`name: string | null;`), and the rest become optional (`currency?: string | null;`). Nullability is inconsistent through `allOf`, though. `SubscriptionJsonhal` drops `| null` on every member except the enum (`name: string;`, `billingCycle: SubscriptionJsonhalBillingCycleEnum | null;`), and the collection's `embedded` becomes `{...} | null`. The generator also logs "OpenAPI 3.1 support is still in beta".
- **3.0.0 downgrade:** `nullable: true` renders as `T | null` consistently, in `SubscriptionJsonhal` too.
- In all variants, `FromJSON` turns `null` into `undefined` for optional members (`'currency': json['currency'] == null ? undefined : json['currency']`). The server also omits null members in HAL: `SubscriptionTest.php:68` lists the keys of a collection row with no `currency` or `notes`.

### Representation per operation and collection models

The Canonical Client Spec (`.cache/canonical-client-spec.json`) keeps one representation per operation:

| Operation | Request | 2xx response | Errors | Injected `Accept` default |
|---|---|---|---|---|
| `GET /subscriptions`, `GET /expense-categories` | – | `application/hal+json` collection | – | `application/hal+json` |
| `GET /subscriptions/{id}`, `GET /expense-categories/{id}` | – | `application/json` → `Subscription` / `ExpenseCategory` | 404 problem+json | `application/json` |
| `POST` (both) | `application/json` → `Subscription` / `ExpenseCategory` | 201 `hal+json` → `*.jsonhal` | 400/422 problem+json | `application/hal+json` |
| `PATCH` (both) | `application/merge-patch+json` → `*.jsonMergePatch` | 200 `hal+json` → `*.jsonhal` | 400/404/422 problem+json | `application/hal+json` |
| `DELETE` (both) | – | 204 | 404 problem+json | none (no body) |
| `GET /reports/dashboard` | – | `hal+json` → `DashboardReport.jsonhal` | 404 problem+json | `application/hal+json` |

The collection GETs return `ApiV1SubscriptionsList200Response` and `ApiV1expenseCategoriesGetCollection200Response`, shaped `{ totalItems?, itemsPerPage?, embedded: {[key]: Array<SubscriptionJsonhal>}, links }`. Measured against the server:

- **Embedded items are not deserialized.** `'embedded': json['_embedded']` passes the raw JSON through, because typescript-fetch does not map a map-of-arrays. Items are typed `SubscriptionJsonhal` but at runtime carry `_links` (not `links`), and `createdAt` is a `string` (not a `Date`). The probe printed `list item: typeof createdAt = string | has _links = true | has links = false`. The key the server uses is `item` (`$data['_embedded']['item']`, `SubscriptionTest.php:65`), which the schema does not name.
- **Pagination links are partly lost.** The contract's `HalCollectionBaseSchema` override replaces `_links` with `first/last/next/previous`. As a result `self` disappears from the collection model, and the server's `prev` (`SubscriptionTest.php:162`) does not match the schema's `previous`. The probe printed `links = {"first":…,"last":…} | self link kept? false` for a page-2 response that also carried `self` and `prev`.
- **An empty page has no `_embedded`** (`SubscriptionTest.php:168`). The schema marks it required, so `embedded` is `undefined` at runtime while typed as present.
- **Categories:** the model is the same envelope built on `HalCollectionBaseSchemaNoPagination` (`links` holds only `self`). This is consistent with the unpaginated list (`ExpenseCategoryTest.php:55`).

### `Subscription.category`: generated model vs what the server returns

- **Generated:** `Subscription.category?: string | null`, the IRI, read from the plain-JSON schema used for `GET /subscriptions/{id}`. `SubscriptionJsonhal` (POST/PATCH responses and list items) also declares a direct `category` member. Its `links` type (`DashboardReportJsonhalAllOfLinks`) has only `self`.
- **Server with no `Accept`:** HAL. `ContentNegotiationTrait::getRequestFormat` falls back to `array_key_first($formats)`, which is `jsonhal` per `config/packages/api_platform.yaml` (`vendor/api-platform/core/src/Metadata/Util/ContentNegotiationTrait.php:131-132`). The fetch API sends `Accept: */*`, which also negotiates to HAL (`SubscriptionFormatsTest::testHalIsTheDefaultFormat`). In HAL the relation is `_links.category.href`, and there is no top-level `category` (`SubscriptionTest.php:68,74,114`). Only `Accept: application/json` returns `category` as a direct IRI (`SubscriptionFormatsTest.php:65-73`).
- **typescript-fetch sends no `Accept` by default.** The probe's `show` call without `accept` sent only `{"Authorization":"RAW"}`. The parsed HAL body gave `category = undefined`.
- **Consequence:** the typed SDK exposes a subscription's category only when the caller explicitly passes `apiV1SubscriptionsShow({ id, accept: "application/json" })`. POST and PATCH responses and list items lose it entirely: the HAL schema declares it as a direct member, but the server sends it under `_links`, and the generated `links` deserializer keeps only `self`. The HAL item schema passes the format tests anyway (`assertMatchesResourceItemJsonSchema`), because `category` is optional and extra members are allowed. That is why the drift went unnoticed.

### Merge-patch, `writeOnly`/`readOnly`, money and dates

- **PATCH:** sends `Content-Type: application/merge-patch+json`. The body type is `Omit<SubscriptionJsonMergePatch, 'id'|'convertedCurrency'|'pendingReview'|'createdAt'|'updatedAt'>`, with every member optional and `T | null`. `ToJSON` writes every key, but `JSON.stringify` drops `undefined`, so only the members supplied are sent and an explicit `null` clears a member. This matches RFC 7396 and the processor's "supplied members" logic.
- **`readOnly`** (`id`, `convertedCurrency`, `pendingReview`, `createdAt`, `updatedAt`): emitted as `readonly` and `Omit`-ted from request types. Passing `pendingReview` in a PATCH is a type error, confirmed with `@ts-expect-error`.
- **`writeOnly`** (`confirmConvertedFor`) has no special treatment. It is writable in POST and PATCH (correct) but also appears as an optional member of the read models (`Subscription`, `SubscriptionJsonhal`). That is harmless, because the server never sends it (`SubscriptionTest.php:119`).
- **Money** (`amount`, `convertedAmount`): plain `string` with the decimal pattern only in the doc comment. **`nextPayment`** is a plain `string` (the contract has only a pattern, no `format: date`), so it is not converted to `Date`. **`createdAt`/`updatedAt`** (`format: date-time`) are converted to `Date` in models that are deserialized, but not inside `_embedded` (see above).

### `application/problem+json` errors

- The models `ModelError` (renamed from `Error`, 47 rename warnings) and `ConstraintViolation` (`violations[].propertyPath/message/code/hint/payload`, plus `type`/`detail`/`title`/`status`) are generated but **not wired to any error path**. `BaseAPI.request` throws `ResponseError(response)` for any status outside 2xx, with the untouched `Response`. A caller has to run `await e.response.json()` and pick the model by status. The probe type-checks this pattern:

```ts
if (e instanceof ResponseError) {
  const body = await e.response.json();
  if (e.response.status === 422) ConstraintViolationFromJSON(body).violations?.forEach(v => v.propertyPath);
  else ModelErrorFromJSON(body).type; // "/errors/404", "/problems/category-in-use", ...
}
```

- The contract documents only 400, 404 and 422. The 401 and 403 from `ApiAccessListener` (`/problems/insufficient-scope`, `/problems/account-inactive`), the 409 domain problems (`/problems/subscription-limit-reached`, `/problems/currency-conflict`, `/problems/category-in-use` in `src/Api/DomainExceptionTranslator.php`), and the 406 are not in the contract. They reach the caller as the same untyped `ResponseError`.

### `oauth` security scheme, relative URLs and `servers: [{url: "/"}]`

- **`basePath`:** `BASE_PATH = "http://localhost"`, which is the generator's substitute for the relative server `/`. Operation paths already carry the prefix (`` `/api/v1/subscriptions/{id}` ``), so the caller has to pass the origin only: `new Configuration({ basePath: "https://<host>" })`. Without it, every call goes to `http://localhost`.
- **Auth:** the `authorizationCode` scheme becomes `Configuration.accessToken?: string | Promise<string> | ((name?, scopes?) => string | Promise<string>)`. It is called on every request as `accessToken("oauth", ["api:full"])`, so a refreshing token provider fits naturally. **The value is written verbatim into `Authorization`, without a `Bearer ` prefix** (`headerParameters["Authorization"] = await this.configuration.accessToken(...)`, and the probe sent `"Authorization":"RAW"`). The server's authenticator only engages when the header starts with `Bearer ` (`vendor/league/oauth2-server-bundle/src/Security/Authenticator/OAuth2Authenticator.php:47`), so a raw token gets a 401. The caller must supply `` accessToken: async () => `Bearer ${token}` ``. The relative `/authorize` and `/token` URLs are not used by the generated runtime at all.

### Warnings, failures and JsonHub leftovers

- **Failure:** 3.2.0 cannot be parsed (see above).
- **Warnings:** 47× `Error … Renamed to ModelError`, one "OpenAPI 3.1 support is still in beta" (3.1 relabel only), and one `ExamplesUtils` notice.
- **Generated output:** has no JsonHub strings. `package.json` carries generator placeholders (`"author": "OpenAPI-Generator"`, `"url": "https://github.com/GIT_USER_ID/GIT_REPO_ID.git"`, `"version": "1.0.0"`, and no `files`).
- **Release-time metadata:** this is where the JsonHub leftovers are. `src/package-metadata.mjs` hardcodes `PACKAGE_NAME_BY_TARGET.ts = "jsonhub-sdk"`. `manifests/ts/package.metadata.json` points at `jsonhub-sdk-ts` with the keyword `jsonhub`. `scripts/generate-and-decide.sh` hardcodes `CLI_PACKAGE_NAME="jsonhub-sdk"`. `publish-to-target.sh` hardcodes the `jsonhub-sdk-ts` repository and the `.jsonhub/` state directory. The normalizer's comments speak of JsonHub, but its policy code has no product-specific branches. All of this is already the subject of #270.
- **Naming:** method names come from API Platform route names and are inconsistent: `apiV1SubscriptionsShow` vs `apiV1expenseCategoriesIdGet`. Collection links reuse a model named `DashboardReportJsonhalAllOfLinksSelf`.

## Problems that block a usable SDK

1. **The contract is OpenAPI 3.2.0, which openapi-generator (7.21.0 pinned, 7.26.0 latest) cannot parse.** Cheapest fix: have the pipeline fetch `/api/docs.jsonopenapi?spec_version=3.0.0`, which is supported by API Platform and gives the cleanest nullability. Alternatives are a generator-side downgrade, or waiting for upstream 3.2 support. *This ticket did not anticipate it.*
2. **`Bearer ` prefix:** the oauth2 template sends the token verbatim, and the server ignores a header without `Bearer `. *(Feeds #272.)*
3. **No `Accept` is sent by default, so single-resource GETs get HAL while their model is plain JSON, and `category` arrives as `undefined`.** *(The premise of #271, confirmed.)*
4. **The HAL schemas in the contract do not describe the HAL the server sends.** Relations sit under `_links` (`category`), not as direct members. Collection `_links` loses `self`. The server emits `prev` where the schema says `previous`. `_embedded.item` is unnamed, and `_embedded` is absent on an empty page. Because the policy picks HAL for collections, POST and PATCH, those responses lose `category` even with a correct `Accept`.
5. **`_embedded` items are not deserialized** (typescript-fetch map-of-arrays), so list items are raw JSON typed as models (`_links` vs `links`, string vs `Date`).
6. **The subscription list filters are missing from the contract.** `order[name|price|renewal]` and `category` are handled in `SubscriptionCollectionProvider` but not declared (the list only declares `page`), so the SDK cannot express them.
7. **Errors are untyped.** Only `ResponseError` with a raw `Response` is available, and 401, 403, 406 and 409 are undocumented. This is workable by hand, but a CLI needs a documented recipe or a small helper.

## Cosmetic

- `Error` is renamed to `ModelError`. The method and model names derived from API Platform route names are long and inconsistent.
- The placeholders in the generated `package.json` are overwritten by `write-package-metadata` at release time, once that step is generalised (#270).
- `writeOnly` `confirmConvertedFor` is present on read models. Money and `nextPayment` are untyped strings, with the pattern in comments only.
- Nullability differs between the 3.1 and 3.0 encodings (3.0 is consistent).

## Impact on sibling tickets

- **#271 (Accept/representation):** still valid, with its premise confirmed. Its scope should widen, though. Fixing `Accept` repairs only the single-resource GETs. Collections, POST and PATCH are HAL by policy, and their models do not match the wire (items 4 and 5). Either the policy picks `application/json` for those as well, which gives up pagination metadata (plain JSON collections are bare arrays per ADR 0007), or the HAL schema and deserialization are fixed.
- **#272 (base URL / token):** still valid. Add the facts that `basePath` must be the origin (paths already include `/api/v1`), that `accessToken` accepts an async provider called on every request but needs a `Bearer ` prefix, and that a 401 surfaces only as `ResponseError` with `response.status === 401` and a `WWW-Authenticate` header.
- **New ticket candidates:**
  - the 3.2.0 → 3.0.0 contract version question (pipeline URL vs generator downgrade);
  - declaring the subscription list's `order`/`category` query parameters in the contract (this repository);
  - correcting the published HAL schemas: `_links.category`, `prev`, `_links.self` on collections, `_embedded.item` (this repository, possibly an upstream API Platform issue).
