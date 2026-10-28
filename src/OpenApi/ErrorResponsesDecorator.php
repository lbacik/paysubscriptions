<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Example;
use ApiPlatform\OpenApi\Model\Header;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;

/**
 * Declares every error status the API v1 operations actually return (issue
 * #280, parent #276): 401/403 on all 11 `/api/v1` operations in one loop,
 * 409/422 from a map keyed by `operationId`, and removal of the Dashboard
 * report's never-returned 404.
 *
 * Each added response reuses the existing `Error`/`ConstraintViolation`
 * schemas and the existing `application/problem+json` and `application/json`
 * media types, lists its problem `type` values in its `description`, and
 * carries named `examples` (each a full problem document) on the
 * `application/problem+json` media type. No per-status schemas or `type`
 * enums are added; `406` and `5xx` are deliberately not declared.
 *
 * Currency-conflict placement was confirmed against the existing
 * Subscription tests: only the PATCH confirm-against-main-currency path
 * throws it (a mismatched `confirmConvertedFor`), while a create carries no
 * confirmation and fails currency rules with 422. The map therefore declares
 * `currency-conflict` on the update only, not on the create — a deliberate
 * deviation from the #276 table, which listed it on both.
 */
final class ErrorResponsesDecorator implements OpenApiFactoryInterface
{
    private const PROBLEM = 'application/problem+json';
    private const JSON = 'application/json';

    public function __construct(
        private readonly OpenApiFactoryInterface $decorated,
    ) {
    }

    /**
     * @param array{base_url?: string, filter_tags?: string|string[]}&array<string, mixed> $context
     */
    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        foreach ($openApi->getPaths()->getPaths() as $path => $pathItem) {
            if (!str_starts_with($path, '/api/v1')) {
                continue;
            }
            $openApi->getPaths()->addPath($path, $this->decoratePathItem($pathItem));
        }

        return $openApi;
    }

    private function decoratePathItem(PathItem $pathItem): PathItem
    {
        $decorated = $pathItem;
        foreach ([
            'getGet' => 'withGet',
            'getPut' => 'withPut',
            'getPost' => 'withPost',
            'getDelete' => 'withDelete',
            'getPatch' => 'withPatch',
        ] as $getter => $wither) {
            $operation = $pathItem->{$getter}();
            if ($operation instanceof Operation) {
                $decorated = $decorated->{$wither}($this->decorateOperation($operation));
            }
        }

        return $decorated;
    }

    private function decorateOperation(Operation $operation): Operation
    {
        $operationId = $operation->getOperationId() ?? '';
        $responses = $operation->getResponses() ?? [];

        if ('api_v1_reports_dashboard' === $operationId) {
            // The report has no id lookup and never 404s.
            unset($responses['404']);
        }

        $responses['401'] ??= self::unauthorized();
        $responses['403'] ??= self::forbidden();

        foreach (self::extraResponses($operationId) as $status => $response) {
            $responses[$status] ??= $response;
        }

        return $operation->withResponses($responses);
    }

    /**
     * The per-operation 409/422 map, keyed by `operationId`.
     *
     * @return array<string, Response>
     */
    private static function extraResponses(string $operationId): array
    {
        return match ($operationId) {
            'api_v1_subscriptions_list' => ['422' => self::subscriptionListViolations()],
            'api_v1_reports_dashboard' => ['422' => self::dashboardViolations()],
            'api_v1_subscriptions_create' => ['409' => self::subscriptionLimitReached()],
            'api_v1_subscriptions_update' => ['409' => self::currencyConflict()],
            'api_v1expense-categories_id_delete' => ['409' => self::categoryInUse()],
            default => [],
        };
    }

    private static function unauthorized(): Response
    {
        return new Response(
            'Unauthorized. The request carries no usable bearer token (problem type "/errors/401").',
            self::content('Error', [
                'unauthorized' => new Example(
                    summary: 'Missing or invalid token',
                    value: [
                        'type' => '/errors/401',
                        'title' => 'An error occurred',
                        'status' => 401,
                        'detail' => 'Unauthorized',
                    ],
                ),
            ]),
            new \ArrayObject([
                'WWW-Authenticate' => new Header(
                    description: 'The bearer challenge the client must answer.',
                    schema: ['type' => 'string'],
                    example: 'Bearer',
                ),
            ]),
            new \ArrayObject([]),
        );
    }

    private static function forbidden(): Response
    {
        return new Response(
            'Forbidden. Either the token lacks the "api:full" scope ("/problems/insufficient-scope") or the account is not eligible ("/problems/account-inactive"). Neither case should trigger a token refresh.',
            self::content('Error', [
                'insufficient-scope' => new Example(
                    summary: 'Token without the api:full scope',
                    value: [
                        'type' => '/problems/insufficient-scope',
                        'title' => 'Forbidden',
                        'status' => 403,
                        'detail' => 'This resource requires the "api:full" scope.',
                    ],
                ),
                'account-inactive' => new Example(
                    summary: 'Unverified account',
                    value: [
                        'type' => '/problems/account-inactive',
                        'title' => 'Forbidden',
                        'status' => 403,
                        'detail' => 'This account is not eligible to use the API.',
                    ],
                ),
            ]),
            null,
            new \ArrayObject([]),
        );
    }

    private static function subscriptionLimitReached(): Response
    {
        return new Response(
            'Conflict. The per-User Subscription limit is reached ("/problems/subscription-limit-reached").',
            self::content('Error', [
                'subscription-limit-reached' => new Example(
                    summary: 'Subscription limit reached',
                    value: [
                        'type' => '/problems/subscription-limit-reached',
                        'title' => 'Subscription limit reached',
                        'status' => 409,
                        'detail' => 'You have reached the maximum number of subscriptions.',
                    ],
                ),
            ]),
            null,
            new \ArrayObject([]),
        );
    }

    private static function currencyConflict(): Response
    {
        return new Response(
            'Conflict. The converted amount was confirmed for a different main currency ("/problems/currency-conflict").',
            self::content('Error', [
                'currency-conflict' => new Example(
                    summary: 'Converted amount confirmed for the wrong currency',
                    value: [
                        'type' => '/problems/currency-conflict',
                        'title' => 'Converted amount currency conflict',
                        'status' => 409,
                        'detail' => 'The converted amount was confirmed for USD, but your current main currency is PLN.',
                    ],
                ),
            ]),
            null,
            new \ArrayObject([]),
        );
    }

    private static function categoryInUse(): Response
    {
        return new Response(
            'Conflict. The category still has Subscriptions assigned ("/problems/category-in-use"). Reassign them first.',
            self::content('Error', [
                'category-in-use' => new Example(
                    summary: 'Category still in use',
                    value: [
                        'type' => '/problems/category-in-use',
                        'title' => 'Expense category in use',
                        'status' => 409,
                        'detail' => 'Category "Subscriptions" cannot be deleted because it still has 1 subscription(s) assigned. Reassign them first.',
                    ],
                ),
            ]),
            null,
            new \ArrayObject([]),
        );
    }

    private static function subscriptionListViolations(): Response
    {
        return new Response(
            'Unprocessable entity. An "order" or "category" selector is invalid (problem type "/validation_errors/{id}", a fresh id per occurrence).',
            self::constraintContent([
                'order' => new Example(
                    summary: 'Invalid sort selector',
                    description: 'The "type" carries a per-occurrence id; only the "/validation_errors/" prefix is stable.',
                    value: [
                        'type' => '/validation_errors/00000000-0000-4000-8000-000000000000',
                        'title' => 'An error occurred',
                        'status' => 422,
                        'detail' => 'order: Sort must be a single "order[field]" parameter with direction "asc" or "desc".',
                        'violations' => [[
                            'propertyPath' => 'order',
                            'message' => 'Sort must be a single "order[field]" parameter with direction "asc" or "desc".',
                            'code' => null,
                        ]],
                    ],
                ),
                'category' => new Example(
                    summary: 'Malformed category filter',
                    description: 'The "type" carries a per-occurrence id; only the "/validation_errors/" prefix is stable.',
                    value: [
                        'type' => '/validation_errors/00000000-0000-4000-8000-000000000000',
                        'title' => 'An error occurred',
                        'status' => 422,
                        'detail' => 'category: The selected category is invalid.',
                        'violations' => [[
                            'propertyPath' => 'category',
                            'message' => 'The selected category is invalid.',
                            'code' => null,
                        ]],
                    ],
                ),
            ]),
            null,
            new \ArrayObject([]),
        );
    }

    private static function dashboardViolations(): Response
    {
        return new Response(
            'Unprocessable entity. A "chartMode", "basis", "month" or "category" selector is invalid (problem type "/validation_errors/{id}", a fresh id per occurrence).',
            self::constraintContent([
                'chartMode' => new Example(
                    summary: 'Unknown chart mode',
                    description: 'The "type" carries a per-occurrence id; only the "/validation_errors/" prefix is stable.',
                    value: [
                        'type' => '/validation_errors/00000000-0000-4000-8000-000000000000',
                        'title' => 'An error occurred',
                        'status' => 422,
                        'detail' => 'chartMode: The value you selected is not a valid choice.',
                        'violations' => [[
                            'propertyPath' => 'chartMode',
                            'message' => 'The value you selected is not a valid choice.',
                            'code' => '8e179f1b-97aa-4560-a02f-2a8b42e49df7',
                        ]],
                    ],
                ),
                'basis' => new Example(
                    summary: 'Unknown basis',
                    description: 'The "type" carries a per-occurrence id; only the "/validation_errors/" prefix is stable.',
                    value: [
                        'type' => '/validation_errors/00000000-0000-4000-8000-000000000000',
                        'title' => 'An error occurred',
                        'status' => 422,
                        'detail' => 'basis: The value you selected is not a valid choice.',
                        'violations' => [[
                            'propertyPath' => 'basis',
                            'message' => 'The value you selected is not a valid choice.',
                            'code' => '8e179f1b-97aa-4560-a02f-2a8b42e49df7',
                        ]],
                    ],
                ),
                'month' => new Example(
                    summary: 'Month outside 1-12',
                    description: 'The "type" carries a per-occurrence id; only the "/validation_errors/" prefix is stable.',
                    value: [
                        'type' => '/validation_errors/00000000-0000-4000-8000-000000000000',
                        'title' => 'An error occurred',
                        'status' => 422,
                        'detail' => 'month: This value should be between 1 and 12.',
                        'violations' => [[
                            'propertyPath' => 'month',
                            'message' => 'This value should be between 1 and 12.',
                            'code' => '04b91c99-a946-4221-afc5-e65ebac401eb',
                        ]],
                    ],
                ),
                'category' => new Example(
                    summary: 'Malformed category filter',
                    description: 'The "type" carries a per-occurrence id; only the "/validation_errors/" prefix is stable.',
                    value: [
                        'type' => '/validation_errors/00000000-0000-4000-8000-000000000000',
                        'title' => 'An error occurred',
                        'status' => 422,
                        'detail' => 'category: This value is not a valid UUID.',
                        'violations' => [[
                            'propertyPath' => 'category',
                            'message' => 'This value is not a valid UUID.',
                            'code' => '51120b12-a2bc-41bf-aa53-cd73daf330d0',
                        ]],
                    ],
                ),
            ]),
            null,
            new \ArrayObject([]),
        );
    }

    /**
     * @param array<string, Example> $examples
     */
    private static function content(string $schema, array $examples): \ArrayObject
    {
        return new \ArrayObject([
            self::PROBLEM => new MediaType(
                schema: new \ArrayObject(['$ref' => '#/components/schemas/'.$schema]),
                examples: new \ArrayObject($examples),
            ),
            self::JSON => new MediaType(
                schema: new \ArrayObject(['$ref' => '#/components/schemas/'.$schema]),
            ),
        ]);
    }

    /**
     * @param array<string, Example> $examples
     */
    private static function constraintContent(array $examples): \ArrayObject
    {
        return self::content('ConstraintViolation', $examples);
    }
}
