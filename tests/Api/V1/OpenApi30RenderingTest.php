<?php

declare(strict_types=1);

namespace App\Tests\Api\V1;

use ApiPlatform\Test\ApiTestCase;

/**
 * OpenAPI 3.0 rendering for the SDK pipeline (parent #276).
 *
 * openapi-generator cannot parse the published OpenAPI 3.2.0 document, so
 * the pipeline reads API Platform's own 3.0 rendering at
 * `/api/docs.jsonopenapi?spec_version=3.0.0`. This repository adds no code
 * for that route; this test proves the route keeps serving the same API
 * Contract, with every correction from #277, #278 and #280 included.
 *
 * The committed snapshot stays the published 3.2 document: no second
 * snapshot is committed and openapi-generator does not run here.
 */
final class OpenApi30RenderingTest extends ApiTestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function committedContract(): array
    {
        $path = \dirname(__DIR__, 3).'/resources/openapi/v1.json';
        self::assertFileExists($path);

        $doc = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($doc, 'The v1 OpenAPI contract must be valid JSON.');

        return $doc;
    }

    /**
     * @return array<string, mixed>
     */
    private function rendering(): array
    {
        $client = static::createClient();

        // The route the pipeline reads (the export command is not used):
        // public, over HTTP, with no token.
        $response = $client->request('GET', '/api/docs.jsonopenapi?spec_version=3.0.0', [
            'headers' => ['accept' => 'application/vnd.openapi+json'],
        ]);

        self::assertSame(200, $response->getStatusCode(), 'The OpenAPI 3.0 rendering must be reachable over HTTP with no token.');

        $doc = json_decode($response->getContent(), true);
        self::assertIsArray($doc, 'The OpenAPI 3.0 rendering must be valid JSON.');

        return $doc;
    }

    public function testRenderingServesTheSameContractAsOpenApi30(): void
    {
        $doc = $this->rendering();
        self::assertSame('3.0.0', $doc['openapi'] ?? null, 'The SDK rendering must be OpenAPI 3.0.0.');

        self::assertNoArrayValuedType($doc);
        self::assertNoJsonSchemaDialect($doc);

        $committed = self::committedContract();

        self::assertSame(
            array_keys($committed['paths'] ?? []),
            array_keys($doc['paths'] ?? []),
            'The 3.0 rendering must carry the same set of paths as the committed 3.2 snapshot.',
        );
        self::assertSame(
            self::operationSet($committed),
            self::operationSet($doc),
            'The 3.0 rendering must carry the same set of operations (method + path) as the committed 3.2 snapshot.',
        );
        self::assertSame(
            array_keys($committed['components']['schemas'] ?? []),
            array_keys($doc['components']['schemas'] ?? []),
            'The 3.0 rendering must carry the same set of component schema names as the committed 3.2 snapshot.',
        );
    }

    public function testRenderingKeepsTheHalCorrections(): void
    {
        $doc = $this->rendering();

        $collection = self::resolve(
            $doc['paths']['/api/v1/subscriptions']['get']['responses']['200']['content']['application/hal+json']['schema'] ?? null,
            $doc,
        );
        self::assertIsArray($collection, 'The Subscription collection must declare a HAL response schema in the 3.0 rendering.');
        $links = $collection['properties']['_links']['properties'] ?? null;
        self::assertIsArray($links, 'The HAL collection must declare a `_links` object.');
        self::assertArrayHasKey('prev', $links, 'The HAL collection must declare `_links.prev` (#277).');
        self::assertArrayHasKey('self', $links, 'The HAL collection must declare `_links.self` (#277).');
        $embedded = $collection['properties']['_embedded']['properties'] ?? null;
        self::assertIsArray($embedded, 'The HAL collection must declare an `_embedded` object.');
        self::assertArrayHasKey('item', $embedded, 'The HAL collection must declare a named `_embedded.item` (#277).');

        $item = self::resolve($doc['components']['schemas']['Subscription.jsonhal'] ?? null, $doc);
        self::assertIsArray($item, 'The HAL Subscription item schema must exist in the 3.0 rendering.');
        $itemLinks = $item['properties']['_links']['properties'] ?? null;
        self::assertIsArray($itemLinks, 'The HAL Subscription item must declare a `_links` object.');
        self::assertArrayHasKey('category', $itemLinks, 'The HAL Subscription item must declare `_links.category` (#277).');
    }

    public function testRenderingKeepsTheListParameters(): void
    {
        $doc = $this->rendering();

        $list = $doc['paths']['/api/v1/subscriptions']['get'] ?? null;
        self::assertIsArray($list, 'The Subscription list operation must exist in the 3.0 rendering.');
        $byName = [];
        foreach ($list['parameters'] ?? [] as $parameter) {
            if (\is_array($parameter) && isset($parameter['name'])) {
                $byName[$parameter['name']] = $parameter;
            }
        }

        foreach (['order[name]', 'order[price]', 'order[renewal]', 'category'] as $name) {
            self::assertArrayHasKey($name, $byName, sprintf('The 3.0 rendering list must declare "%s" (#278).', $name));
            self::assertSame('query', $byName[$name]['in'] ?? null);
        }
    }

    public function testRenderingKeepsTheDeclaredErrors(): void
    {
        $doc = $this->rendering();

        foreach ($doc['paths'] ?? [] as $path => $operations) {
            if (!\is_string($path) || !str_starts_with($path, '/api/v1') || !\is_array($operations)) {
                continue;
            }
            foreach ($operations as $method => $operation) {
                if (!\is_array($operation) || !isset($operation['operationId'])) {
                    continue;
                }
                foreach (['401', '403'] as $status) {
                    self::assertArrayHasKey(
                        $status,
                        $operation['responses'] ?? [],
                        sprintf('Operation "%s" must declare %s in the 3.0 rendering (#280).', $operation['operationId'], $status),
                    );
                }
            }
        }

        self::assertContains(
            '/problems/subscription-limit-reached',
            self::exampleTypes($doc, 'api_v1_subscriptions_create', '409'),
            'The Subscription create 409 must list subscription-limit-reached in its examples (#280).',
        );
        self::assertContains(
            '/problems/currency-conflict',
            self::exampleTypes($doc, 'api_v1_subscriptions_update', '409'),
            'The Subscription update 409 must list currency-conflict in its examples (#280).',
        );
        self::assertContains(
            '/problems/category-in-use',
            self::exampleTypes($doc, 'api_v1expense-categories_id_delete', '409'),
            'The category delete 409 must list category-in-use in its examples (#280).',
        );
    }

    /**
     * @return list<string>
     */
    private static function operationSet(array $doc): array
    {
        $methods = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];
        $set = [];
        foreach ($doc['paths'] ?? [] as $path => $operations) {
            if (!\is_array($operations)) {
                continue;
            }
            foreach ($operations as $method => $operation) {
                if (\in_array(strtolower((string) $method), $methods, true) && \is_array($operation)) {
                    $set[] = strtoupper((string) $method).' '.$path;
                }
            }
        }
        sort($set);

        return $set;
    }

    private static function assertNoArrayValuedType(mixed $node, string $path = '$'): void
    {
        if (\is_array($node)) {
            foreach ($node as $key => $value) {
                // A property literally named "type" (e.g. the problem
                // `type` member) carries a schema object as its value;
                // only the `type` keyword (a string, or a 3.1 list of
                // strings) is checked here.
                if ('type' === $key && (\is_string($value) || (\is_array($value) && array_is_list($value)))) {
                    self::assertIsString($value, sprintf('"%s.type" must be a single string in OpenAPI 3.0, got an array at %s.', $path, $path));
                } else {
                    self::assertNoArrayValuedType($value, $path.'.'.(string) $key);
                }
            }
        }
    }

    private static function assertNoJsonSchemaDialect(mixed $node, string $path = '$'): void
    {
        if (\is_array($node)) {
            foreach ($node as $key => $value) {
                self::assertNotSame('jsonSchemaDialect', (string) $key, sprintf('"jsonSchemaDialect" must not appear in OpenAPI 3.0 (at %s).', $path));
                self::assertNoJsonSchemaDialect($value, $path.'.'.(string) $key);
            }
        }
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private static function exampleTypes(array $document, string $operationId, string $status): array
    {
        $operation = self::findOperation($document, $operationId);
        self::assertArrayHasKey($status, $operation['responses'] ?? [], sprintf('Operation "%s" must declare status %s in the 3.0 rendering.', $operationId, $status));

        $examples = $operation['responses'][$status]['content']['application/problem+json']['examples'] ?? null;
        self::assertIsArray($examples, sprintf('Operation "%s" status %s must carry named examples.', $operationId, $status));
        self::assertNotEmpty($examples);

        $types = [];
        foreach ($examples as $example) {
            if (isset($example['value']['type'])) {
                $types[] = $example['value']['type'];
            }
        }

        return $types;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function findOperation(array $document, string $operationId): array
    {
        foreach ($document['paths'] ?? [] as $operations) {
            if (!\is_array($operations)) {
                continue;
            }
            foreach ($operations as $candidate) {
                if (\is_array($candidate) && ($candidate['operationId'] ?? null) === $operationId) {
                    return $candidate;
                }
            }
        }

        self::fail(sprintf('Operation "%s" must be declared in the 3.0 rendering.', $operationId));
    }

    /**
     * Inlines local component refs and merges `allOf` branches so property
     * assertions read the effective schema in either spec version.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>|mixed
     */
    private static function resolve(mixed $schema, array $document, array $refStack = []): mixed
    {
        if (\is_array($schema) && isset($schema['$ref']) && \is_string($schema['$ref'])) {
            $ref = $schema['$ref'];
            self::assertStringStartsWith('#/components/schemas/', $ref);
            $name = substr($ref, \strlen('#/components/schemas/'));
            self::assertFalse(\in_array($name, $refStack, true), sprintf('Circular $ref "%s" cannot be resolved.', $ref));
            self::assertArrayHasKey($name, $document['components']['schemas'] ?? [], sprintf('Component schema "%s" is missing from the 3.0 rendering.', $name));

            return self::resolve($document['components']['schemas'][$name], $document, [...$refStack, $name]);
        }

        if (!\is_array($schema)) {
            return $schema;
        }

        if (isset($schema['allOf']) && \is_array($schema['allOf'])) {
            $merged = [];
            foreach ($schema['allOf'] as $branch) {
                $merged = self::mergeSchemas($merged, self::resolve($branch, $document, $refStack));
            }
            unset($schema['allOf']);
            $schema = self::mergeSchemas($merged, $schema);
        }

        foreach ($schema as $key => $value) {
            if (\in_array($key, ['description', 'example', 'default', 'format', 'title'], true)) {
                continue;
            }
            $schema[$key] = self::resolve($value, $document, $refStack);
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overlay
     *
     * @return array<string, mixed>
     */
    private static function mergeSchemas(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if ('properties' === $key && isset($base[$key]) && \is_array($base[$key]) && \is_array($value)) {
                foreach ($value as $prop => $propSchema) {
                    if (isset($base[$key][$prop]) && \is_array($base[$key][$prop]) && \is_array($propSchema)) {
                        $base[$key][$prop] = self::mergeSchemas($base[$key][$prop], $propSchema);
                    } else {
                        $base[$key][$prop] = $propSchema;
                    }
                }
            } elseif ('required' === $key) {
                $base[$key] = array_values(array_unique(array_merge($base[$key] ?? [], (array) $value)));
            } elseif (!\array_key_exists($key, $base) || null !== $value) {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
