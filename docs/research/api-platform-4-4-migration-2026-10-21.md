# API Platform 4.4 capabilities for the API v1 migration

Research for [#244](https://github.com/lbacik/paysubscriptions/issues/244) (map [#243](https://github.com/lbacik/paysubscriptions/issues/243)). Date: 2026-10-21. Facts only: this note does not choose the contract, the format or the modeling approach (that is [#245](https://github.com/lbacik/paysubscriptions/issues/245), [#246](https://github.com/lbacik/paysubscriptions/issues/246) and [#247](https://github.com/lbacik/paysubscriptions/issues/247)).

**Installed versions checked:** `api-platform/core` v4.4.5, Symfony 7.4, `doctrine/orm` 3.7.5, `league/oauth2-server-bundle` 1.2.2, PHPUnit 9.6.38. `symfony/object-mapper` and `justinrainbow/json-schema` are **not** installed.

**Sources and how claims were checked:**

- **Docs:** the official docs repository, branch [`4.4`](https://github.com/api-platform/docs/tree/4.4) (snapshot `201dd36`, 2026-10-01), cited below as *docs*.
- **Source:** the installed source in `vendor/api-platform/core` (same as tag [`v4.4.5`](https://github.com/api-platform/core/tree/v4.4.5)), cited as *src*.
- **Where they disagree, the source wins** and the mismatch is noted (see [Version caveats](#version-caveats)).
- **Behavior the source shows but the docs do not state** is marked "(src)".
- **Nothing here was run against a migrated endpoint.** Claims marked "runtime check needed" are reasoned from the code.

## Customization points at a glance

These are the places where API Platform's defaults differ from today's hand-written v1 contract, or where it gives no out-of-the-box answer:

1. **Plain JSON collections have no pagination metadata.** With `application/json`, a collection is a bare array. Only JSON-LD/Hydra (or HAL / JSON:API) carries totals and page links.
2. **PATCH accepts only `application/merge-patch+json` by default.** `application/json` on PATCH gets 415 unless it is added to `patch_formats`.
3. **Null properties are omitted from responses by default.** `skip_null_values: true` applies to every response, not only to merge-patch.
4. **Errors use a different shape.**
   - Generic errors carry `type: "/errors/{status}"` and `title: "An error occurred"`.
   - Validation errors carry `type: "/validation_errors/{constraint codes}"` and a `violations[]` array of `propertyPath`/`message`/`code`.
   - There is no `code` member and no stable app-defined `type` unless the app adds custom exceptions or providers.
   - Malformed JSON gives 400 (today: 422).
5. **Firewall 401 responses are not API Platform errors.** The league authenticator returns plain-text 401 responses, so something like `ApiProblemResponseListener` is still needed for them. The same applies to 404s for unrouted `/api/v1/*` paths: `handle_symfony_errors` would cover those, but it is global (see Errors).
6. **Sorting by computed values needs custom code.** Built-in sorting (`SortFilter`/`OrderFilter`) works only on Doctrine columns. `price` (converted amount) and `renewal` (projected date) are computed, so they need a custom provider or custom filter.
7. **Default Doctrine write processors bypass domain services.** Persisting through the built-in processor would skip `SubscriptionService`/`ExpenseCategoryService` rules, so a custom processor is needed either way.
8. **DTO-to-entity auto-mapping needs `symfony/object-mapper`**, which is not installed. This is the 4.x `stateOptions: new Options(entityClass: …)` with `#[Map]`.
9. **Swagger UI OAuth needs extra wiring.** You must set `flow: authorizationCode` and `pkce: true` (the default `flow` is `application`), and override the OAuth2 redirect URL (the default resolves to the non-existent `/api/oauth2-redirect.html`). You also need a registered public client for Swagger UI.
10. **Public `/api/docs` needs a security carve-out.** Today `/api/docs` sits behind `access_control` `^/api` → `IS_AUTHENTICATED_FULLY`, so it needs an earlier `PUBLIC_ACCESS` rule.

---

## 1. Formats

### Enabling formats

- **Only JSON-LD is on by default.** Other formats are enabled by listing them under `api_platform.formats`, where the first entry is the default ([docs: content-negotiation](https://github.com/api-platform/docs/blob/4.4/core/content-negotiation.md#configuring-formats-globally)). Raw `json` is supported, but the docs mark it "Backward Compatibility guaranteed: no" and say they discourage it in favor of JSON-LD (same page, format table).
- **Built-in defaults** (src [`Configuration.php`](https://github.com/api-platform/core/blob/v4.4.5/src/Symfony/Bundle/DependencyInjection/Configuration.php)):
  - `formats`: `jsonld`
  - `patch_formats`: `json: [application/merge-patch+json]`
  - `error_formats`: `jsonld`, `jsonproblem` (`application/problem+json`), and `json` (`application/problem+json`, `application/json`)
  - `docs_formats`: `jsonld`, `jsonopenapi`, `html`, plus `yamlopenapi` when `symfony/yaml` is present (it is)
- **Formats can be narrowed per resource or operation** with `formats`, `inputFormats` and `outputFormats` ([docs](https://github.com/api-platform/docs/blob/4.4/core/content-negotiation.md#configuring-formats-for-a-specific-resource-or-operation)).
- **Negotiation rules:** the format comes from the `Accept` header or the URL extension. With no `Accept`, the first configured format is used. An unsupported request `Content-Type` gives 415 (src `ContentNegotiationProvider`, `DeserializeProvider`).
- **Hydra key prefix:** in 4.x, Hydra keys are unprefixed by default (`serializer.hydra_prefix: false`): `member`, `totalItems`, `view` rather than `hydra:member` (src `Configuration.php`).

### Collections per format

| Format | Collection body | Pagination metadata |
|---|---|---|
| `json` (`application/json`) | bare JSON array of items | **none** (src: there is no JSON collection normalizer, only Hydra/HAL/JSON:API have one; the Symfony serializer turns the paginator into a plain array) |
| `jsonld` (Hydra) | object with `@context`, `@id`, `@type: Collection`, `member` | `totalItems`, and `view` with `first`/`last`/`previous`/`next` ([docs: pagination](https://github.com/api-platform/docs/blob/4.4/core/pagination.md)) |
| `jsonhal` / `jsonapi` | format-specific envelope | links/meta (src `Hal/Serializer/CollectionNormalizer`, `JsonApi/Serializer/CollectionNormalizer`) |

- **Plain JSON pagination signals:** there is no header-based pagination (no `Link`/`X-Total-Count`) for plain JSON. `Hydra/State/Util/PaginationHelperTrait` is used only by Hydra code paths (src).
- **Partial pagination** (`paginationPartial: true`) drops `totalItems`/`last` and avoids the COUNT query ([docs](https://github.com/api-platform/docs/blob/4.4/core/pagination.md#partial-pagination)).
- **Relations default to IRIs** in both JSON-LD and plain JSON, for example `"category": "/api/v1/expense_categories/<uuid>"`. Writes accept IRIs for relations. Exposing a scalar `categoryId` instead requires a DTO or custom property mapping ([docs: serialization, Embedding Relations](https://github.com/api-platform/docs/blob/4.4/core/serialization.md#embedding-relations)).

### Null output

- **Nulls are skipped by default.** `SerializerContextBuilder` sets `skip_null_values ??= true` for every request (src [`Serializer/SerializerContextBuilder.php`](https://github.com/api-platform/core/blob/v4.4.5/src/Serializer/SerializerContextBuilder.php)). The docs show the same default under `defaults.normalization_context.skip_null_values: true` "in conformance with the JSON Merge Patch RFC" ([docs: configuration](https://github.com/api-platform/docs/blob/4.4/core/configuration.md)).
- **Today's v1 differs.** It emits explicit `null` members (for example `amount`, `nextPayment`, `categoryId`, `notes`), so keeping them means setting `normalizationContext: ['skip_null_values' => false]` per resource or in `defaults`.

### PATCH input and partial updates

- **Merge Patch is the default PATCH format.** A PATCH operation with no explicit formats gets `inputFormats = patch_formats`, plus an `Accept-Patch` header (src `FormatsResourceMetadataCollectionFactory`).
  - Sending `Content-Type: application/json` on PATCH therefore gives **415**.
  - Accepting it means adding the MIME type to the `json` entry of `patch_formats` (or to the operation's `inputFormats`). Both MIME types then go through the same `json` deserializer. This is source-derived, not documented as a recommendation. (src)
  - The docs page says merge-patch "must be enabled explicitly". In 4.4.5 it is already in the default `patch_formats` (src `Configuration.php`).
- **How partial updates work.** For PATCH, `api_allow_update` and `deep_object_to_populate` are set, and the stored object is deserialized onto (`OBJECT_TO_POPULATE`) (src `SerializerContextBuilder`, `DeserializeProvider`):
  - omitted keys are left unchanged;
  - an explicit `null` calls the setter or property with `null`.
- **No RFC 7396 algorithm of its own.** API Platform does not implement a separate merge-patch algorithm. "merge-patch+json" mainly drives the JSON Schema/OpenAPI definition name (src `JsonSchema/SchemaFactory.php`). So nested-object null semantics are the Symfony Serializer's populate semantics.
- **Explicit `null` on a non-nullable property** raises `NotNormalizableValueException`. In 4.4.5 that gives **400**, except:
  - invalid backed-enum values give 422;
  - with `collectDenormalizationErrors: true`, errors are collected and returned as a 422 violation list (src [`State/Provider/DeserializeProvider.php`](https://github.com/api-platform/core/blob/v4.4.5/src/State/Provider/DeserializeProvider.php); [docs: Collecting Denormalization Errors](https://github.com/api-platform/docs/blob/4.4/symfony/validation.md#collecting-denormalization-errors)). See the caveat on "constraint-aware 422" below.
- **Not built in:** today's "No updatable fields supplied" 422 for an empty PATCH body, and telling "omitted" apart from "null" inside a DTO processor. A processor can compare against `previous_data` or inspect the request body.
- **Malformed JSON body** raises a serializer exception, which maps to **400** through the default `exception_to_status` (today's v1 returns 422).

## 2. Pagination, filtering, sorting

### Pagination

- **On by default, 30 per page** ([docs](https://github.com/api-platform/docs/blob/4.4/core/pagination.md)). A fixed 25 means `paginationItemsPerPage: 25` on the resource or operation, or `defaults.pagination_items_per_page: 25`. Client control of page size is off by default (`paginationClientItemsPerPage`).
- **The page parameter** is `page` and 1-based by default (`collection.pagination.page_parameter_name`).
  - `page` < 1 (including non-numeric values, which cast to 0) throws `ApiPlatform\Metadata\Exception\InvalidArgumentException('Page should not be less than 1')`, which maps to **400** (src [`State/Pagination/Pagination.php`](https://github.com/api-platform/core/blob/v4.4.5/src/State/Pagination/Pagination.php)).
  - Today's v1 clamps to page 1 instead.
- **Doctrine-backed operations** paginate in SQL. Custom providers must return a `PaginatorInterface`/`PartialPaginatorInterface` (for example `ArrayPaginator`, `TraversablePaginator`) to be paginated ([docs: Pagination for Custom State Providers](https://github.com/api-platform/docs/blob/4.4/core/pagination.md#pagination-for-custom-state-providers)). The `ApiPlatform\State\Pagination\Pagination` service gives page, offset and limit for the operation (src).

### Filtering by `categoryId`

- **Declare filters as `QueryParameter`.** The 4.x docs strongly recommend parameters over the legacy `#[ApiFilter]` ([docs: filters](https://github.com/api-platform/docs/blob/4.4/core/filters.md#declaring-parameters)). Type-specific filters (`ExactFilter`, `IriFilter`, `UuidFilter`, …) are preferred over `SearchFilter` ([docs: doctrine-filters](https://github.com/api-platform/docs/blob/4.4/core/doctrine-filters.md#built-in-search-filters-since-api-platform--42)).
- **Entity ids are Symfony `UuidType`.** On MySQL they are binary. `UuidFilter` converts UUID strings through Doctrine's type for the field. It also handles associations: given a property like `category`, it resolves the target entity's id type (src [`Doctrine/Orm/Filter/AbstractUuidFilter.php`](https://github.com/api-platform/core/blob/v4.4.5/src/Doctrine/Orm/Filter/AbstractUuidFilter.php); [docs: UUID Support](https://github.com/api-platform/docs/blob/4.4/core/doctrine-filters.md#uuid-support)).
- **A query key like `categoryId` mapped to the `category` property** looks like `'categoryId' => new QueryParameter(filter: new UuidFilter(), property: 'category')`. This is source-derived; runtime check needed.
- **A malformed UUID value** raises `InvalidArgumentException` and gives **400** (src). Today's dashboard report returns 404 for a malformed `categoryId`.
- **Parameters are documented and validated automatically.** They show up in OpenAPI. Constraints, `required` and JSON-Schema-derived rules give 422 via `ValidationException` (src `Symfony/Validator/State/ParameterValidatorProvider.php`). `strictQueryParameterValidation: true` returns 400 for unknown query parameters ([docs](https://github.com/api-platform/docs/blob/4.4/core/filters.md#strict-parameter-validation)).

### Sorting

- **Doctrine:** use `SortFilter`, one `QueryParameter` per sortable property, values `asc|desc`, anything else is a 422 ([docs: Sort Filter](https://github.com/api-platform/docs/blob/4.4/core/doctrine-filters.md#sort-filter)). The legacy `OrderFilter` uses `order[prop]=asc`.
  - Both sort only by mapped fields or associations.
  - Today's `sort=price` (converted to the User's main currency) and `sort=renewal` (projected next renewal) are **computed in PHP** (`SubscriptionService::get`), so neither filter can express them.
- **Custom providers (non-entity or DTO resources):**
  - Doctrine filters and extensions do not run ([docs: extensions](https://github.com/api-platform/docs/blob/4.4/core/extensions.md)).
  - A parameter can still be declared without a filter, for documentation, validation, `default` and enum `schema`.
  - `ParameterProvider` resolves every declared parameter's value before the provider runs. The provider reads it via `$operation->getParameters()->get('sort')?->getValue()`, which returns `ParameterNotFound` when absent (src [`State/Provider/ParameterProvider.php`](https://github.com/api-platform/core/blob/v4.4.5/src/State/Provider/ParameterProvider.php)).
  - The raw query is also in `$context['filters']` (src `ReadProvider`).
  - Sorting and slicing in PHP, then returning an `ArrayPaginator`, matches today's "filter and sort the whole collection, then slice" approach.

## 3. Resource modeling

- **The design guide** says the `#[ApiResource]` class is meant to be the public contract (a POPO/DTO) fed by providers and processors. Putting `#[ApiResource]` on a Doctrine entity is described as convenient for CRUD/RAD and "probably not a good idea for non-CRUD and/or large systems" ([docs: design](https://github.com/api-platform/docs/blob/4.4/core/design.md)). The DTO page calls DTO-as-resource the recommended pattern ([docs: dto](https://github.com/api-platform/docs/blob/4.4/core/dto.md)).
- **Option A, entity as `#[ApiResource]`:**
  - You get built-in Doctrine `ItemProvider`/`CollectionProvider`, filters, pagination, eager loading, extensions and `PersistProcessor`/`RemoveProcessor`.
  - Public and internal models are shared. Serialization groups decide the exposed fields, and relations serialize as IRIs.
  - Writes through the default `PersistProcessor` would **not** call `SubscriptionService`/`ExpenseCategoryService` (limit, ownership, converted-amount review, owner assignment). A custom or decorating processor is needed to reuse them ([docs: state-processors, Hooking into the Built-In State Processors](https://github.com/api-platform/docs/blob/4.4/core/state-processors.md#hooking-into-the-built-in-state-processors)).
- **Option B, DTO resource with `stateOptions: new Options(entityClass: …)`:**
  - The built-in Doctrine provider and processor run against the entity, and Doctrine filters/extensions apply to the entity query.
  - `ObjectMapperProvider`/`ObjectMapperProcessor` map entity ↔ DTO with Symfony ObjectMapper and `#[Map]`. Separate `input` (Create/Update) and `output` DTOs are supported ([docs: dto](https://github.com/api-platform/docs/blob/4.4/core/dto.md#1-the-dto-resource-state-options)).
  - **This requires `symfony/object-mapper` ^7.4, which is not installed.** Without it, the mapping decorators pass the entity through unchanged (src [`State/Provider/ObjectMapperProvider.php`](https://github.com/api-platform/core/blob/v4.4.5/src/State/Provider/ObjectMapperProvider.php)).
  - `Options` also accepts `repositoryMethod` to start the query from a repository method ([docs](https://github.com/api-platform/docs/blob/4.4/core/state-providers.md#customizing-the-doctrine-query-via-repositorymethod-symfony-only)).
- **Option C, DTO resource with fully custom provider and processor:**
  - The provider and processor call existing services and repositories directly. This is the general pattern in the design guide.
  - Pagination, filtering, sorting and owner scoping are then the provider's job, since Doctrine filters/extensions do not apply.
  - A custom processor for a business action can take an input DTO and the loaded entity from `$context['request']->attributes->get('read_data')` ([docs: dto §3](https://github.com/api-platform/docs/blob/4.4/core/dto.md#3-custom-business-logic-custom-processor)).
- **URL shape:**
  - The default path segment generator is `underscore` (`/expense_categories`). `api_platform.metadata.path_segment_name_generator.dash` exists for `/expense-categories`, or set an explicit `uriTemplate` (src `Configuration.php`, `config/api.php`).
  - `routePrefix: '/v1'` on `#[ApiResource]` combines with the existing `/api` route-import prefix (src `Metadata/ApiResource.php`).
- **Identifiers:**
  - A property typed `Symfony\Component\Uid\Uuid` goes through `UuidUriVariableTransformer`. A malformed UUID in the path raises `InvalidUriVariableException`, which `MainController` converts to **404** "Invalid uri variables." (src [`Symfony/Controller/MainController.php`](https://github.com/api-platform/core/blob/v4.4.5/src/Symfony/Controller/MainController.php)). That matches today's contract.
  - A `string` id on a DTO is not transformed, so the provider must validate it.

## 4. Non-entity resources (`GET /api/v1/reports/dashboard`)

- **Shape:** a plain class with `#[ApiResource]` and a single `new Get(uriTemplate: '/reports/dashboard', provider: DashboardReportProvider::class, parameters: [...])` with no URI variables. API Platform's own fixture uses this shape: `new Get(uriTemplate: 'redirect_to_foobar', provider: [...])` ([core fixture `Headers.php`](https://github.com/api-platform/core/blob/v4.4.5/tests/Fixtures/TestBundle/ApiResource/Headers.php)).
- **Query parameters** (`chartMode`, `basis`, `month`, `categoryId`):
  - Declare them as `QueryParameter`s with `schema` (for example `enum`, `default`) or `constraints` plus `nativeType`. That gives OpenAPI documentation and 422 validation for free ([docs: Parameter Validation](https://github.com/api-platform/docs/blob/4.4/core/filters.md#parameter-validation)).
  - Read the values in the provider as described in §2.
  - Cross-field rules (for example `month` only valid for some `chartMode`/`basis` combinations) and the "category must be owned" check stay in provider or service code. A `ParameterProviderInterface` implementation can also transform values ([docs: Creating a Custom Parameter Provider](https://github.com/api-platform/docs/blob/4.4/core/filters.md#creating-a-custom-parameter-provider)).
- **Missing data:** if the provider returns `null`, the `ReadProvider` throws 404 for GET. `throwOnNotFound: false` (new in 4.4) lets `null` through ([docs: operations, Controlling the 404 Response](https://github.com/api-platform/docs/blob/4.4/core/operations.md#controlling-the-404-response-when-data-is-missing); src `ReadProvider`).

## 5. Security

### Expressions with the league OAuth2 User

- **What the attributes do:**
  - `security` runs after the provider and before deserialization; `object` is the stored state.
  - `securityPostDenormalize` runs after deserialization; `object` is the incoming state and `previous_object` a shallow clone of the stored one.
  - Variables: `user`, `object`, `previous_object`, and `request` (resource level only).
  - Since 4.4 there is also `access_decision` (voter reasons) ([docs: symfony/security](https://github.com/api-platform/docs/blob/4.4/symfony/security.md)).
  - An operation-level denial throws 403 (`AccessDeniedHttpException`/`AccessDeniedException`) (src `Symfony/Security/State/AccessCheckerProvider.php`).
- **`user` here:**
  - The league `OAuth2Authenticator` loads the user through the firewall's user provider, so for User-delegated tokens `user` is `App\Entity\User`. Client-credentials tokens yield a `ClientCredentialsUser` (src [league `OAuth2Authenticator.php`](https://github.com/thephpleague/oauth2-server-bundle/blob/v1.2.2/src/Security/Authenticator/OAuth2Authenticator.php)).
  - `OAuth2Token` merges the User's roles with one role per scope (`ROLE_OAUTH2_` + uppercased scope), so `api:full` becomes `ROLE_OAUTH2_API:FULL` (src league `Security/Authentication/Token/OAuth2Token.php`).
- **Ordering with `ApiAccessListener`:**
  - API Platform runs inside its `MainController` by default (`use_symfony_listeners: false`), so the existing `ApiAccessListener` (`kernel.request`, priority -5) still runs before any provider.
  - The firewall and `access_control` run earlier still.
  - These checks are unaffected by the migration.

### Scoping collections and returning 404 for foreign IDs

- **Docs guidance:** filter collections in the provider, not with `security` expressions. For the built-in Doctrine providers, use extensions. Voters cannot be used on `GetCollection` ([docs: Filtering Collection According to the Current User Permissions](https://github.com/api-platform/docs/blob/4.4/symfony/security.md#filtering-collection-according-to-the-current-user-permissions)).
- **Doctrine extensions:** a class implementing `QueryCollectionExtensionInterface` **and** `QueryItemExtensionInterface` adds `WHERE o.owner = :user` to both collection and item queries ([docs: extensions](https://github.com/api-platform/docs/blob/4.4/core/extensions.md#custom-doctrine-orm-extension)).
  - A foreign ID then finds nothing, the provider returns `null`, and `ReadProvider` throws **404** for GET, PATCH and DELETE ([docs: operations](https://github.com/api-platform/docs/blob/4.4/core/operations.md#controlling-the-404-response-when-data-is-missing)).
  - Extensions apply only to the built-in Doctrine providers, including the `stateOptions` entityClass path.
- **Expressions give 403, not 404.** A `security: "object.owner == user"` expression returns **403**, which today's contract avoids.
- **Custom providers:** they must scope queries themselves and return `null` for foreign or missing IDs to get the same 404.
- **Writes that reference another resource** (for example `categoryId` on a Subscription) need an explicit ownership check in the processor, validator or voter. `securityPostDenormalize` can do it but answers 403.

## 6. Errors

### The 4.x error resource and `application/problem+json`

- **Errors are rendered as resources.** The error listener turns an exception into `ApiPlatform\State\ApiResource\Error`, an `#[ErrorResource]` (src [`State/ApiResource/Error.php`](https://github.com/api-platform/core/blob/v4.4.5/src/State/ApiResource/Error.php), [`Symfony/EventListener/ErrorListener.php`](https://github.com/api-platform/core/blob/v4.4.5/src/Symfony/EventListener/ErrorListener.php)).
- **Content type follows the negotiated format:**
  - `json` and `jsonproblem` requests get `application/problem+json`.
  - `jsonld` requests get the Hydra variant, also served as `application/problem+json`, with `@context`/`@type`.
- **Body for a generic exception** (`Error::createFromException`):
  - `type: "/errors/{status}"`, `title: "An error occurred"`, `detail` (the exception message), `status`.
  - `instance` is omitted when null.
  - `code`, `trace`, `file` and `line` are ignored attributes, so there is **no `code` member**.
- **Detail in production:** for status ≥ 500 the detail is replaced by a generic message when not in debug mode. For 4xx, the exception message reaches the client ([docs: errors, Message Scope](https://github.com/api-platform/docs/blob/4.4/core/errors.md#message-scope)).
- **Exceptions that implement `ProblemExceptionInterface`** (`getType`, `getTitle`, `getStatus`, `getDetail`, `getInstance`) have those values copied into the response. This is how a **stable custom `type`** (for example `https://…/problems/subscription_limit_reached`) is produced. Adding `#[ErrorResource]` to the exception class makes it its own error resource, with extra public properties serialized, and lets operations list it in `errors: [...]` so it is documented in OpenAPI ([docs: errors, Domain exceptions / Document your exceptions](https://github.com/api-platform/docs/blob/4.4/core/errors.md#domain-exceptions)).
- **Replacing the error shape globally** (for example to add `code`) means replacing `api_platform.state.error_provider`, or `api_platform.validator.state.error_provider` for validation ([docs: Control your exceptions](https://github.com/api-platform/docs/blob/4.4/core/errors.md#control-your-exceptions)).

### Status decision and `exception_to_status`

- **Order of checks** (src `ErrorListener::getStatusCode`; [docs](https://github.com/api-platform/docs/blob/4.4/core/errors.md#exception-status-code-decision)):
  1. `exception_to_status` (global, then resource, then operation; the operation wins), matched with `is_a`, first match in array order
  2. Symfony `HttpExceptionInterface`
  3. `ProblemExceptionInterface::getStatus()`
  4. API Platform `HttpExceptionInterface`
  5. `RequestExceptionInterface` / `InvalidUriVariableException` → 400
  6. `ConstraintViolationListAwareExceptionInterface` → 422
  7. The `ErrorResource` status
  8. 500
- **Defaults in 4.4.5** (src `Configuration.php`): `Symfony\Component\Serializer\Exception\ExceptionInterface` → 400, `ApiPlatform\Metadata\Exception\InvalidArgumentException` → 400, `Doctrine\ORM\OptimisticLockException` → 409.
  - A custom global `exception_to_status` **replaces** the defaults, not merges with them ([docs warning](https://github.com/api-platform/docs/blob/4.4/core/errors.md#exception-to-status-configuration-using-symfony)).
  - The class names in that docs snippet (`ApiPlatform\Exception\InvalidArgumentException`, `ApiPlatform\ParameterValidator\…`) do not exist in 4.4.5. Copy the defaults from the source.
- **Mapping this app's exceptions:**
  - `SubscriptionLimitReachedException` → 409 and `DuplicateCategoryNameException` → 409 can be expressed as `exception_to_status`, for example per resource with `exceptionToStatus: [...]`.
  - The resulting body is the generic `Error` (`type: "/errors/409"`). A stable `type` needs `ProblemExceptionInterface` on the exception or a translating processor.
  - `CategoryOwnershipException` and `SubscriptionLimitReachedException` extend `\LogicException`. The services also throw bare `\LogicException` (category in use, invalid category) and `\InvalidArgumentException` (field validation). Mapping those base classes globally would also catch unrelated framework exceptions, so they need catching or translating in processors.
- **Scope:** API Platform only renders errors for its own routes (requests with the `_api_respond` attribute) unless `handle_symfony_errors: true` (src [`Symfony/EventListener/ExceptionListener.php`](https://github.com/api-platform/core/blob/v4.4.5/src/Symfony/EventListener/ExceptionListener.php)). Consequences:
  - **401 from the firewall** is a plain-text `Response` returned by the league authenticator's `start()`/`onAuthenticationFailure()`, not an exception, so API Platform never formats it.
  - **No route matched** (for example `/api/v1/unknown`) produces Symfony's error page.
  - **403 from `access_control` or API Platform security** on an API Platform route does pass through the API Platform error renderer.
- **Caution on `handle_symfony_errors`:** it is global, not path-scoped. Error format negotiation falls back on `*/*` to the first error format (src `Metadata/Util/ContentNegotiationTrait.php`), so browsers requesting web pages would likely get JSON errors. Runtime check needed before using it.

### Validation 422 shape

`ValidationException` (src [`Validator/Exception/ValidationException.php`](https://github.com/api-platform/core/blob/v4.4.5/src/Validator/Exception/ValidationException.php)) in `json`/`jsonproblem` returns:

```json
{
  "type": "/validation_errors/<violation code or codes>",
  "title": "An error occurred",
  "detail": "name: This value should not be blank.",
  "status": 422,
  "violations": [
    { "propertyPath": "name", "message": "This value should not be blank.", "code": "c1051bb4-…" }
  ]
}
```

- **`hint` and `payload`** appear when present or configured (`validator.serialize_payload_fields`).
- **The `type` is derived from constraint codes,** not an app-stable URI. Today's v1 uses `type: …/validation_failed`, `code`, and `errors: [{field, message}]`.
- **Changing the shape** means replacing `api_platform.validator.state.error_provider` or throwing an app exception from a processor.

## 7. OpenAPI and docs

- **Spec version:** generated documents target **OpenAPI 3.2.0** (`OpenApi::VERSION = '3.2.0'`). Use `?spec_version=3.0.0` or `--spec-version=3.0.0` for 3.0 ([docs: openapi](https://github.com/api-platform/docs/blob/4.4/core/openapi.md); src `OpenApi/OpenApi.php`). Today's static `resources/openapi/v1.json` is 3.0.3.
- **OAuth2 security scheme** (`api_platform.oauth`; [docs: OAuth](https://github.com/api-platform/docs/blob/4.4/core/openapi.md#oauth); src `OpenApiFactory::getOauthSecurityScheme`):
  - **Settings:** `enabled`, `type: oauth2`, `flow`, `authorizationUrl`, `tokenUrl`, `refreshUrl`, `scopes` (map of name → description), `clientId`, `clientSecret`, `pkce`.
  - **`flow`** defaults to `application` (maps to clientCredentials) and must be set to `authorizationCode` (alias `accessCode`) to get an `authorizationCode` flow.
  - **Scheme name:** it is emitted as `oauth`.
  - **Global requirement:** `security: [{"oauth": []}]`, with no scopes listed (src `OpenApiFactory` lines building `$securityRequirements`).
  - **Scopes per operation** go through `openapi: new Model\Operation(security: [['oauth' => ['api:full']]])`, as in [core fixture `Issue5625/Currency.php`](https://github.com/api-platform/core/blob/v4.4.5/tests/Fixtures/TestBundle/Entity/Issue5625/Currency.php), or through a factory decorator.
  - **PKCE** is not part of the OpenAPI flow object. `oauth.pkce: true` only sets Swagger UI's `usePkceWithAuthorizationCodeGrant` (src `SwaggerUiProcessor`, `init-swagger-ui.js`).
  - **`clientSecret`:** the config says never to use it in production; enable `pkce` instead (src `Configuration.php` info text).
- **Decorating the factory:** `#[AsDecorator(decorates: 'api_platform.openapi.factory')]` on a class implementing `OpenApiFactoryInterface` that calls the inner factory and returns a modified `OpenApi` (immutable `with*` model) ([docs: Overriding the OpenAPI Specification](https://github.com/api-platform/docs/blob/4.4/core/openapi.md#overriding-the-openapi-specification); [Symfony service decoration](https://symfony.com/doc/7.4/service_container/service_decoration.html)). Use cases include servers, info, scopes in requirements, and documenting OAuth endpoints that live outside API Platform.
- **CLI export for a snapshot test:**
  - Command: `bin/console api:openapi:export [--output=file] [--yaml] [--spec-version=…] [--filter-tags=…]` ([docs](https://github.com/api-platform/docs/blob/4.4/core/openapi.md#using-the-openapi-command)).
  - It calls `api_platform.openapi.factory` and the serializer (`json` format) without an HTTP request (src [`OpenApi/Command/OpenApiCommand.php`](https://github.com/api-platform/core/blob/v4.4.5/src/OpenApi/Command/OpenApiCommand.php)).
  - A test can run it with `CommandTester`, or call the factory and normalizer directly, then compare with a committed file.
- **Docs routes** today (from `debug:router`, prefix `/api`):
  - `/api/docs` (`api_docs`), `/api/docs.{_format}` (`jsonopenapi`, `jsonld`, `yamlopenapi`, `html`), `/api/docs.json`
  - the entrypoint `/api/{index}.{_format}` and `/api/contexts/*`
  - error routes `/api/errors/{status}` and `/api/validation_errors/{id}`
- **Making `/api/docs` public:**
  - The `api` firewall (`^/api`, stateless, `oauth2: true`) only authenticates requests carrying `Authorization: Bearer`.
  - Anonymous requests are stopped by the `access_control` rule `^/api` → `IS_AUTHENTICATED_FULLY`.
  - A `PUBLIC_ACCESS` rule for the docs paths placed **before** that rule allows anonymous access, because first match wins ([Symfony: access_control / PUBLIC_ACCESS](https://symfony.com/doc/7.4/security.html#allowing-unsecured-access-i-e-anonymous-users)).
  - `ApiAccessListener` skips requests without an `OAuth2Token`, and `ApiProblemResponseListener` only acts on `^/api/v1`.
  - The Swagger UI HTML embeds the whole spec inline (`swagger_data.spec`), so the HTML page does not need a second request to fetch the JSON.
  - "Try it out" calls still need a Bearer token.
- **Swagger UI assets:**
  - They are local files under `/bundles/apiplatform/...`, installed by `assets:install` (already in `composer.json` auto-scripts; `public/bundles/` is git-ignored).
  - `/bundles/` is outside `^/api` and `^/assets/`, so it falls to the lazy `main` firewall with no `access_control` rule. It is public.
  - **Scalar** (enabled by default when Twig is present, used with `?ui=scalar`) loads `https://cdn.jsdelivr.net/npm/@scalar/api-reference` from a CDN, unpinned (src `Resources/views/SwaggerUi/index.html.twig`). `enable_scalar: false` / `enable_re_doc: false` turn the alternatives off ([docs](https://github.com/api-platform/docs/blob/4.4/core/openapi.md#disabling-swagger-ui-redoc-or-scalar)).
- **Swagger UI OAuth redirect:**
  - `init-swagger-ui.js` passes `oauth2RedirectUrl: data.oauth.redirectUrl`, which `SwaggerUiProcessor` never sets. Swagger UI therefore falls back to `<current dir>/oauth2-redirect.html`, which is `/api/oauth2-redirect.html` for `/api/docs` and does not exist.
  - The shipped page is `/bundles/apiplatform/swagger-ui/oauth2-redirect.html`. `swagger.swagger_ui_extra_configuration: { oauth2RedirectUrl: … }` is merged into the Swagger UI config after the defaults (`Object.assign`) and can override it (src).
  - That URL must be a registered redirect URI of the OAuth client Swagger UI uses. The client also has to satisfy `ClientApprovalPolicy` for `api:full`.
- **`SecurityHeadersListener` CSP:**
  - The listener only adds `Content-Security-Policy: frame-ancestors 'none'` and `X-Frame-Options: DENY` when absent. There is no `script-src`/`style-src` policy, so the Swagger UI page, its `<script type="application/json">` data block, local scripts and a CDN script are not blocked.
  - API Platform responses already set `X-Frame-Options: deny`, `X-Content-Type-Options: nosniff` and `Vary: Accept` (src `State/Util/HttpResponseHeadersTrait.php`), and the listener does not overwrite existing headers.
  - The OAuth popup flow uses `window.open`, not an iframe, so `frame-ancestors 'none'` on `/authorize` does not affect it.
  - A future `script-src` policy would need `cdn.jsdelivr.net` if Scalar stays enabled. Runtime check needed in a browser.

## 8. Testing

- **`ApiTestCase` basics:**
  - `ApiPlatform\Symfony\Bundle\Test\ApiTestCase` extends `KernelTestCase`, not `WebTestCase`. The existing `App\Tests\DatabaseTestCase extends WebTestCase` would need an API-specific sibling base.
  - Its client implements Symfony `HttpClientInterface` over the kernel (no network) and needs `symfony/browser-kit` + `symfony/http-client`; both are installed ([docs: symfony/testing](https://github.com/api-platform/docs/blob/4.4/symfony/testing.md#the-test-httpclient); src [`Symfony/Bundle/Test/ApiTestCase.php`](https://github.com/api-platform/core/blob/v4.4.5/src/Symfony/Bundle/Test/ApiTestCase.php)).
- **Assertions:** `assertJsonContains`, `assertJsonEquals`, and the BrowserKit response assertions are available. `assertMatchesResourceItemJsonSchema` / `…CollectionJsonSchema` need `justinrainbow/json-schema`, which is not installed.
- **Version caveats for the test client:**
  - `static::$alwaysBootKernel` left `null` triggers a 4.1 deprecation and always boots the kernel. Set it to `true` or `false` explicitly; the 5.0 default is `false`.
  - The class uses PHPUnit **attributes** `#[Before]`/`#[After]` for exception-handler cleanup and verbose BrowserKit assertions. PHPUnit 9.6 ignores attributes, so those hooks do not run. The test client itself does not depend on them (src).
  - If `application/json` is not among the configured formats, `createClient()` defaults `Content-Type` to the first configured format (src `withDefaultContentType`). PATCH tests must set `Content-Type: application/merge-patch+json` explicitly unless JSON is added to `patch_formats`.
- **Foundry and transactions:**
  - The docs example combines `ApiTestCase` with Foundry's `ResetDatabase` and `Factories` traits, plus DAMA's PHPUnit extension ([docs: Writing Functional Tests](https://github.com/api-platform/docs/blob/4.4/symfony/testing.md#writing-functional-tests)).
  - This app's `App\Tests\DatabaseTransactionExtension` is a DAMA `StaticDriver` wrapper registered at the PHPUnit level, so it is independent of the base class.
  - The client reboots the kernel between requests by default (`disableReboot()` to opt out). With DAMA static connections the open transaction survives the reboot, but each request gets a fresh container and EntityManager.
- **OAuth2 bearer tokens in tests:**
  - Pass `['auth_bearer' => $jwt]` (Symfony HttpClient option, turned into `Authorization: Bearer …` by `HttpClientTrait::prepareRequest`) or an explicit `headers: ['Authorization' => 'Bearer …']`.
  - The existing `ExpenseCategoryApiTestCase::craftToken()` already mints signed `ApiAccessTokenEntity` JWTs with the fixture private key and registers the client, which stays reusable as is.
  - `Client::loginUser()` creates a session-style token for a firewall context. It does not produce an `OAuth2Token`, so it would not pass `ApiAccessListener` on `^/api/v1`.

## Version caveats

- **The 4.4 docs branch describes a 5.0 feature.**
  - `symfony/validation.md` "Constraint-Aware 422 for Denormalization Errors" says "Starting with API Platform 4.4" and mentions `DenormalizationViolationFactory`.
  - The installed v4.4.5 has no such class. The [CHANGELOG 4.4.0 notes](https://github.com/api-platform/core/blob/v4.4.5/CHANGELOG.md) say it "has been moved to 5.0. 4.4.0 keeps the 4.3 behaviour".
  - So a type mismatch on a constrained property gives **400**; only backed-enum errors and `collectDenormalizationErrors` mode give 422.
- **Stale `exception_to_status` class names** appear in the docs (see §6).
- **Merge Patch** is described as "must be enabled explicitly" but is in the 4.4.5 defaults (see §1).
- **New in 4.4:** `throwOnNotFound`, the `access_decision` expression variable, and `ApiPlatform\Metadata\Exception\AccessDeniedException` (replacing the deprecated `ApiPlatform\Symfony\Security\Exception\AccessDeniedException`) ([docs: symfony/security](https://github.com/api-platform/docs/blob/4.4/symfony/security.md#throwing-accessdeniedexception-directly)). All are present in 4.4.5 (src).
- **Deprecations in 4.x:** the `ApiFilter` attribute (and the `AbstractFilter` base class) in favor of `QueryParameter` filters; `ValidationException` with a string first argument; `query_parameter_validation` and `enable_link_security` config.
- **API Platform 5.0 is released** (v5.0.4, 2026-10-16). `composer.json` pins `^4.2`, so 5.0 is a separate upgrade.
- **DTO `stateOptions` with Object Mapper** is documented as needing `symfony/object-mapper` ^7.4 or ^8.0, and as "Symfony only, 4.2+".

## Sources

- API Platform docs, branch 4.4 (`201dd36`): [content-negotiation](https://github.com/api-platform/docs/blob/4.4/core/content-negotiation.md), [operations](https://github.com/api-platform/docs/blob/4.4/core/operations.md), [pagination](https://github.com/api-platform/docs/blob/4.4/core/pagination.md), [filters](https://github.com/api-platform/docs/blob/4.4/core/filters.md), [doctrine-filters](https://github.com/api-platform/docs/blob/4.4/core/doctrine-filters.md), [extensions](https://github.com/api-platform/docs/blob/4.4/core/extensions.md), [design](https://github.com/api-platform/docs/blob/4.4/core/design.md), [dto](https://github.com/api-platform/docs/blob/4.4/core/dto.md), [state-providers](https://github.com/api-platform/docs/blob/4.4/core/state-providers.md), [state-processors](https://github.com/api-platform/docs/blob/4.4/core/state-processors.md), [serialization](https://github.com/api-platform/docs/blob/4.4/core/serialization.md), [errors](https://github.com/api-platform/docs/blob/4.4/core/errors.md), [openapi](https://github.com/api-platform/docs/blob/4.4/core/openapi.md), [configuration](https://github.com/api-platform/docs/blob/4.4/core/configuration.md), [symfony/security](https://github.com/api-platform/docs/blob/4.4/symfony/security.md), [symfony/validation](https://github.com/api-platform/docs/blob/4.4/symfony/validation.md), [symfony/testing](https://github.com/api-platform/docs/blob/4.4/symfony/testing.md).
- API Platform core source at [v4.4.5](https://github.com/api-platform/core/tree/v4.4.5) (identical to `vendor/api-platform/core`): files linked inline above, plus [CHANGELOG](https://github.com/api-platform/core/blob/v4.4.5/CHANGELOG.md).
- league/oauth2-server-bundle source at [v1.2.2](https://github.com/thephpleague/oauth2-server-bundle/tree/v1.2.2): `OAuth2Authenticator`, `OAuth2Token`.
- Symfony 7.4 docs: [security / access_control](https://symfony.com/doc/7.4/security.html#allowing-unsecured-access-i-e-anonymous-users), [service decoration](https://symfony.com/doc/7.4/service_container/service_decoration.html).
- This repository: `config/packages/api_platform.yaml`, `config/packages/security.yaml`, `src/Security/ApiAccessListener.php`, `src/EventListener/ApiProblemResponseListener.php`, `src/EventListener/SecurityHeadersListener.php`, `src/Controller/Api/V1/*`, `src/Api/Problem.php`, `tests/Controller/Api/V1/*`, `tests/DatabaseTransactionExtension.php`, and `bin/console debug:router` output.
