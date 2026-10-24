<?php

declare(strict_types=1);

namespace App\Tests\Api\V1;

use ApiPlatform\Test\Client;
use PHPUnit\Framework\Assert;

/**
 * Shared seam for contract tests (issue #277, parent #276): the published
 * API v1 contract served over HTTP, compared with real responses from API
 * Platform's test client.
 *
 * The document is fetched once per process from `/api/docs.jsonopenapi` and
 * cached; `responseSchema()` resolves the effective response schema for an
 * operation, status and media type (local `$ref`s inlined, `allOf` branches
 * merged), and `assertMatchesStrictly()` validates a response body against
 * it with undeclared members forbidden and `required` enforced at test time.
 * The committed document itself stays open (`additionalProperties` unset);
 * strictness lives here so drift fails CI instead of reaching an SDK.
 *
 * The declared-errors test (a later slice of #276) reuses this helper to
 * assert that a provoked error status is declared on its operation.
 */
final class OpenApiContract
{
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $document = null;

    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(Client $client): array
    {
        if (null === self::$document) {
            // The test client defaults to `Accept: application/hal+json`;
            // the docs format only serves `application/vnd.openapi+json`.
            $response = $client->request('GET', '/api/docs.jsonopenapi', ['headers' => ['accept' => 'application/vnd.openapi+json']]);
            Assert::assertSame(200, $response->getStatusCode(), 'The published OpenAPI document must be reachable over HTTP with no token.');

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($response->getContent(), true);
            Assert::assertIsArray($decoded, 'The published OpenAPI document must be valid JSON.');
            self::$document = $decoded;
        }

        return self::$document;
    }

    public static function resetDocument(): void
    {
        self::$document = null;
    }

    /**
     * Resolves the effective response schema for an operation, status and
     * media type: local `$ref`s are inlined and `allOf` branches merged, so
     * the result is a single closed-world object schema.
     *
     * @return array<string, mixed>
     */
    public static function responseSchema(Client $client, string $operationId, int $status, string $mediaType): array
    {
        $document = self::document($client);

        /** @var array<string, mixed>|null $operation */
        $operation = null;
        foreach ($document['paths'] ?? [] as $operations) {
            if (!\is_array($operations)) {
                continue;
            }
            foreach ($operations as $candidate) {
                if (\is_array($candidate) && ($candidate['operationId'] ?? null) === $operationId) {
                    $operation = $candidate;
                    break 2;
                }
            }
        }
        Assert::assertNotNull($operation, sprintf('Operation "%s" must be declared in the published document.', $operationId));

        $responses = $operation['responses'] ?? [];
        Assert::assertArrayHasKey((string) $status, $responses, sprintf('Operation "%s" must declare status %d in the published document.', $operationId, $status));

        $content = $responses[(string) $status]['content'] ?? [];
        Assert::assertArrayHasKey($mediaType, $content, sprintf('Operation "%s" status %d must declare media type "%s" in the published document.', $operationId, $status, $mediaType));

        $schema = $content[$mediaType]['schema'] ?? null;
        Assert::assertIsArray($schema, sprintf('Operation "%s" status %d media type "%s" must carry a schema.', $operationId, $status, $mediaType));

        /** @var array<string, mixed> $resolved */
        $resolved = self::resolve($schema, $document);

        return $resolved;
    }

    /**
     * Validates a response body against an effective schema with undeclared
     * members forbidden and `required` enforced. Every mismatch is reported
     * with its JSON path.
     *
     * @param array<string, mixed> $schema
     */
    public static function assertMatchesStrictly(array $schema, mixed $data, string $path = '$'): void
    {
        $errors = [];
        self::check($schema, $data, $path, $errors);
        if ([] !== $errors) {
            Assert::fail("Response does not strictly match the published schema:\n- ".implode("\n- ", $errors));
        }
        Assert::assertTrue(true);
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string>        $refStack
     *
     * @return array<string, mixed>
     */
    private static function resolve(mixed $schema, array $document, array $refStack = []): mixed
    {
        if (\is_array($schema) && isset($schema['$ref']) && \is_string($schema['$ref'])) {
            $ref = $schema['$ref'];
            Assert::assertStringStartsWith('#/components/schemas/', $ref, sprintf('Only local component refs can be resolved, got "%s".', $ref));
            $name = substr($ref, \strlen('#/components/schemas/'));
            Assert::assertFalse(\in_array($name, $refStack, true), sprintf('Circular $ref "%s" cannot be resolved.', $ref));
            Assert::assertArrayHasKey($name, $document['components']['schemas'] ?? [], sprintf('Component schema "%s" is missing from the published document.', $name));

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
     * Merges two object schemas: `properties` and `required` are unioned
     * (shared properties merged recursively), every other member comes from
     * the overlay when set.
     *
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
            } elseif ('type' === $key && isset($base[$key]) && $base[$key] !== $value) {
                $base[$key] = array_values(array_unique(array_merge((array) $base[$key], (array) $value)));
            } elseif (!\array_key_exists($key, $base) || null !== $value) {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $errors
     */
    private static function check(mixed $schema, mixed $data, string $path, array &$errors): void
    {
        if (true === $schema) {
            return;
        }
        if (false === $schema) {
            $errors[] = sprintf('%s: forbidden by the schema but present.', $path);

            return;
        }
        if (!\is_array($schema)) {
            $errors[] = sprintf('%s: malformed schema, cannot validate.', $path);

            return;
        }
        if (isset($schema['$ref'])) {
            $errors[] = sprintf('%s: unresolved $ref "%s" (helper bug).', $path, (string) $schema['$ref']);

            return;
        }

        foreach (['allOf', 'oneOf', 'anyOf'] as $combinator) {
            if (!isset($schema[$combinator])) {
                continue;
            }
            $branches = $schema[$combinator];
            if ('allOf' === $combinator) {
                $merged = [];
                foreach ($branches as $branch) {
                    $merged = self::mergeSchemas($merged, $branch);
                }
                self::check($merged + array_diff_key($schema, ['allOf' => true, 'oneOf' => true, 'anyOf' => true]), $data, $path, $errors);

                return;
            }
            $branchErrors = [];
            $passes = 0;
            foreach ($branches as $branch) {
                $attempt = [];
                self::check($branch, $data, $path, $attempt);
                if ([] === $attempt) {
                    ++$passes;
                } else {
                    $branchErrors[] = implode('; ', $attempt);
                }
            }
            if (0 === $passes || ('oneOf' === $combinator && 1 !== $passes)) {
                $errors[] = sprintf('%s: matches no %s branch (%s).', $path, $combinator, implode(' | ', $branchErrors));
            }

            return;
        }

        if (isset($schema['enum']) && \is_array($schema['enum'])) {
            if (!\in_array($data, $schema['enum'], true)) {
                $errors[] = sprintf('%s: %s is not one of %s.', $path, self::dump($data), self::dump($schema['enum']));
            }

            return;
        }
        if (\array_key_exists('const', $schema)) {
            if ($data !== $schema['const']) {
                $errors[] = sprintf('%s: %s does not equal %s.', $path, self::dump($data), self::dump($schema['const']));
            }

            return;
        }

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $matched = false;
            foreach ($types as $type) {
                if (self::isType($type, $data)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $errors[] = sprintf('%s: expected %s, got %s.', $path, self::dump($schema['type']), self::dump($data));

                return;
            }
        }

        if (\is_array($data) && ([] === $data || !array_is_list($data))) {
            self::checkObject($schema, $data, $path, $errors);
        } elseif (\is_array($data)) {
            self::checkArray($schema, $data, $path, $errors);
        } elseif (\is_string($data)) {
            self::checkString($schema, $data, $path, $errors);
        } elseif (\is_int($data) || \is_float($data)) {
            self::checkNumber($schema, $data, $path, $errors);
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $data
     * @param list<string>         $errors
     */
    private static function checkObject(array $schema, array $data, string $path, array &$errors): void
    {
        $properties = $schema['properties'] ?? [];
        foreach ($schema['required'] ?? [] as $required) {
            if (!\array_key_exists($required, $data)) {
                $errors[] = sprintf('%s: required member "%s" is missing.', $path, (string) $required);
            }
        }

        $extrasSchema = $schema['additionalProperties'] ?? null;
        foreach ($data as $member => $value) {
            $memberPath = $path.'.'.$member;
            if (\array_key_exists($member, $properties)) {
                self::check($properties[$member], $value, $memberPath, $errors);
            } elseif (\is_array($extrasSchema)) {
                self::check($extrasSchema, $value, $memberPath, $errors);
            } elseif ([] !== $properties || false === $extrasSchema) {
                $errors[] = sprintf('%s: undeclared member "%s" is forbidden.', $path, (string) $member);
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<mixed>          $data
     * @param list<string>         $errors
     */
    private static function checkArray(array $schema, array $data, string $path, array &$errors): void
    {
        foreach (['minItems' => 'minItems', 'maxItems' => 'maxItems'] as $keyword => $label) {
            if (isset($schema[$keyword]) && ('minItems' === $label ? \count($data) < $schema[$keyword] : \count($data) > $schema[$keyword])) {
                $errors[] = sprintf('%s: %s violated (got %d items).', $path, $label, \count($data));
            }
        }
        if (isset($schema['items'])) {
            foreach ($data as $index => $item) {
                self::check($schema['items'], $item, $path.'['.$index.']', $errors);
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $errors
     */
    private static function checkString(array $schema, string $data, string $path, array &$errors): void
    {
        if (isset($schema['minLength']) && mb_strlen($data) < $schema['minLength']) {
            $errors[] = sprintf('%s: shorter than minLength %d.', $path, $schema['minLength']);
        }
        if (isset($schema['maxLength']) && mb_strlen($data) > $schema['maxLength']) {
            $errors[] = sprintf('%s: longer than maxLength %d.', $path, $schema['maxLength']);
        }
        if (isset($schema['pattern']) && 1 !== preg_match('/'.str_replace('/', '\/', $schema['pattern']).'/', $data)) {
            $errors[] = sprintf('%s: %s does not match %s.', $path, self::dump($data), self::dump($schema['pattern']));
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $errors
     */
    private static function checkNumber(array $schema, int|float $data, string $path, array &$errors): void
    {
        foreach (['minimum' => 'below minimum', 'maximum' => 'above maximum', 'exclusiveMinimum' => 'at or below exclusiveMinimum', 'exclusiveMaximum' => 'at or above exclusiveMaximum'] as $keyword => $message) {
            if (isset($schema[$keyword]) && match ($keyword) {
                'minimum' => $data < $schema[$keyword],
                'maximum' => $data > $schema[$keyword],
                'exclusiveMinimum' => $data <= $schema[$keyword],
                'exclusiveMaximum' => $data >= $schema[$keyword],
            }) {
                $errors[] = sprintf('%s: %s %s %s.', $path, self::dump($data), $message, self::dump($schema[$keyword]));
            }
        }
    }

    private static function isType(mixed $type, mixed $data): bool
    {
        return match ($type) {
            'null' => null === $data,
            'boolean' => \is_bool($data),
            'integer' => \is_int($data),
            'number' => (\is_int($data) || \is_float($data)) && !\is_bool($data),
            'string' => \is_string($data),
            'array' => \is_array($data) && ([] === $data || array_is_list($data)),
            'object' => \is_array($data) && ([] === $data || !array_is_list($data)),
            default => false,
        };
    }

    private static function dump(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        $text = \is_string($json) ? $json : get_debug_type($value);

        return 200 < \strlen($text) ? substr($text, 0, 200).'…' : $text;
    }
}
