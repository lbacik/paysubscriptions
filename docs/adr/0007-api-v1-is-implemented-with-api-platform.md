# API v1 is implemented with API Platform

API v1 started as hand-written controllers next to an unused API Platform install, with a hand-maintained OpenAPI document ([issue #243](https://github.com/lbacik/paysubscriptions/issues/243)). We decided that API Platform serves the v1 resources and generates their OpenAPI document. Each entity-backed resource (ExpenseCategory, Subscription) is a DTO in `src/ApiResource` backed by its entity through `stateOptions(entityClass)` and `symfony/object-mapper`. Reads use API Platform's Doctrine provider, so they get its extensions, filters and paginator. Writes are mapped onto the entity and a resource processor in `src/State` passes them to the existing domain service, so the API and the web app share one set of rules. The dashboard report has no entity: it is a DTO with its own provider. ExpenseCategory is the first resource on this pattern ([issue #254](https://github.com/lbacik/paysubscriptions/issues/254)); the details were settled in [issue #246](https://github.com/lbacik/paysubscriptions/issues/246).

- Services throw named domain exceptions (for example `CategoryInUseException`) and know nothing about API Platform. One API-layer listener, `App\Api\DomainExceptionTranslator`, turns them into a stable `/problems/<slug>` type or a 422 field violation. Processors do not catch them.
- Field constraints shared by the web forms and the API are Symfony `Compound` constraints (`App\Validator`), placed on both the entity and the DTO.
- API Platform also validates the mapped entity in the `Default` group. Web-only entity constraints, such as `UniqueEntity` on the category name, therefore live in the `web` group, which only the web forms validate. The API reports a taken name through the service: a 422 on `name` with the `UniqueEntity::NOT_UNIQUE_ERROR` code.
- A resource whose service needs the client's intent (which PATCH members were sent, write-only members) may have its processor read the DTO instead of the mapped entity. The ticket that introduces it states the reason.

## Response formats

The formats are HAL (`application/hal+json`, the default), plain JSON and JSON-LD ([issue #245](https://github.com/lbacik/paysubscriptions/issues/245)).

- **HAL is the default, not JSON-LD.** JSON-LD's `@id`/`@type`/`@context` collide with each resource's own `id` in TypeScript and Python client generators, which strip the `@`. HAL has pagination links and no `@` members.
- **Plain JSON collections are bare arrays** with no pagination metadata (API Platform behavior). JSON-LD stays available for API Platform tooling.
- **JSON:API is not enabled.** Its `errors[]` format conflicts with problem+json, it has its own PATCH format, and it nests fields under `attributes`.
- **Every error is `application/problem+json`**, whatever the `Accept`. HAL has no error normalizer and would fall back to a JSON-LD Hydra error. Generic errors keep API Platform's `/errors/{status}` type. A domain `/problems/<slug>` type exists only where one status covers several causes a client must tell apart.

## Authorization

These decisions were made in [issue #247](https://github.com/lbacik/paysubscriptions/issues/247).

- **No per-resource `security` for the scope.** `ApiAccessListener` checks the `api:full` scope and `ClientApprovalPolicy` for the token on every `/api/v1` request, before API Platform runs. These are properties of the token, not of a resource. An unknown or inactive Client must answer 401, but a failed `security` expression always answers 403. The policy is also shared with the authorize and refresh checkpoints ([ADR 0005](0005-connections-end-only-by-user-or-removal.md)).
- **Ownership is a scoped read, not security.** `App\Doctrine\UserOwnedExtension` restricts every Doctrine read of an entity implementing `App\Entity\UserOwned` to the token User. A foreign identifier is therefore a 404, identical to a missing one, and a foreign IRI in a write body looks unknown. A `security: "object.owner == user"` expression would answer 403 and disclose that the row exists. An entity-backed resource holding User data cannot ship unscoped: its entity must implement the marker.
- **A generic edge converter** renders, as the generic `/errors/{status}` problem, the errors API Platform does not render: firewall 401s and unrouted `/api/*` paths. `ApiAccessListener` throws exceptions that API Platform renders (401 as `/errors/401`; 403 as `/problems/insufficient-scope` or `/problems/account-inactive`). Rejected alternatives: `handle_symfony_errors` is global and would turn web-page errors into JSON, and decorating the authenticator plus a separate routing listener misses unknown edges.

## Considered Options

- **The entity as the API resource.** It needs the least code. But the deserializer writes into the managed entity before any service rule runs, and the entity used by the web forms would carry API metadata. A duplicate name also came out in two shapes: on POST from the service on `name`, on PATCH from `UniqueEntity` on `owner`.
- **A hand-written DTO provider and processor.** It gives full control, but mapping, scoping, pagination and filters are all hand-written and lose API Platform's Doctrine extensions.

## Consequences

- `symfony/object-mapper` is a dependency. A read-only DTO member needs `#[Map(if: new TargetClass(self::class))]`, because a plain `if: false` blocks both directions and breaks IRI generation. On PATCH the input is mapped onto the managed entity before the processor runs, so a service that refuses a write must leave nothing for a later flush (see `ExpenseCategoryService::update()`).
- The hand-written routes kept their old error shape until the last resource
  migrated. With every resource on API Platform, `ApiAccessListener` throws
  (401 as `/errors/401`; 403 as `/problems/insufficient-scope` or
  `/problems/account-inactive`), the generic edge converter renders the
  `/errors/{status}` problem for what API Platform does not render, and
  `App\Api\Problem` is gone. `resources/openapi/v1.json` describes only the
  hand-written contract document.
