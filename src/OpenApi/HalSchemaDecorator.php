<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\OpenApi;

/**
 * Corrects the published HAL collection and item schemas so they describe
 * what the HAL normalizers actually send (issue #277, parent #276).
 *
 * API Platform's `HalCollectionBaseSchema` says `previous` where
 * `CollectionNormalizer` sends `prev`, omits the collection `self` link from
 * the paginated `_links` block, leaves the `_embedded` item array unnamed
 * (`additionalProperties` instead of a named `item` property, untyped in the
 * base schema) and marks `_embedded` required although an empty page sends
 * none. The HAL item schema is `allOf: [{_links: {self}}, <plain-JSON
 * schema>]`, so the Subscription relation stays declared as a direct
 * `category` member while the wire carries it as `_links.category`.
 *
 * This decorator only rewrites the document; API behaviour doesn't change.
 *
 * Upstream report: https://github.com/lbacik/paysubscriptions/issues/276#issuecomment-6384644650
 * (against api-platform/core; the token available while implementing #277
 * could not create issues outside this repository, so the full report text
 * lives in that comment until it is filed upstream — then point this link
 * at the api-platform/core issue instead). The blocks marked WORKAROUND can
 * be removed once upstream fixes the `HalCollectionBaseSchema` vs
 * `CollectionNormalizer` mismatch.
 */
final class HalSchemaDecorator implements OpenApiFactoryInterface
{
    private const HAL = 'application/hal+json';

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

        $schemas = $openApi->getComponents()->getSchemas();
        if (null !== $schemas) {
            $this->fixCollectionBaseSchemas($schemas);
            $this->fixSubscriptionItemSchema($schemas);
        }
        $this->fixCollectionResponses($openApi);

        return $openApi;
    }

    /**
     * @param \ArrayObject<string, mixed> $schemas
     */
    private function fixCollectionBaseSchemas(\ArrayObject $schemas): void
    {
        // WORKAROUND (upstream: HalCollectionBaseSchema vs CollectionNormalizer
        // mismatch): the paginated base schema declares `previous` but the
        // normalizer sends `prev`, and neither base level names the collection
        // `self` link next to the other page links. Remove `previous`, add
        // `self`, `prev` (and the `_links.item` row-link array the normalizer
        // emits for non-empty pages) once upstream fixes this.
        if (isset($schemas['HalCollectionBaseSchema'])) {
            $base = $this->asArray($schemas['HalCollectionBaseSchema']);
            $links = $base['allOf'][1]['properties']['_links']['properties'] ?? [];
            unset($links['previous']);
            $base['allOf'][1]['properties']['_links'] = [
                'type' => 'object',
                'properties' => ['self' => self::link()] + $links + [
                    'prev' => self::link(),
                    'item' => self::linkArray(),
                ],
            ];
            // An empty page carries no `_embedded` key at all.
            $this->writeBack($schemas, 'HalCollectionBaseSchema', $base);
        }

        if (isset($schemas['HalCollectionBaseSchemaNoPagination'])) {
            $base = $this->asArray($schemas['HalCollectionBaseSchemaNoPagination']);
            // WORKAROUND (upstream, same mismatch): the non-paginated base
            // declares only `self`; declare the same link set as the paginated
            // base so both collections share one shape. Removable together
            // with the block above.
            $base['properties']['_links'] = [
                'type' => 'object',
                'properties' => [
                    'self' => self::link(),
                    'first' => self::link(),
                    'last' => self::link(),
                    'prev' => self::link(),
                    'next' => self::link(),
                    'item' => self::linkArray(),
                ],
            ];
            $base['properties']['_embedded'] = [
                'type' => 'object',
                'properties' => ['item' => ['type' => 'array']],
            ];
            // An empty collection carries no `_embedded` key at all.
            $base['required'] = ['_links'];
            $this->writeBack($schemas, 'HalCollectionBaseSchemaNoPagination', $base);
        }
    }

    /**
     * @param \ArrayObject<string, mixed> $schemas
     */
    private function fixSubscriptionItemSchema(\ArrayObject $schemas): void
    {
        if (!isset($schemas['Subscription.jsonhal'], $schemas['Subscription'])) {
            return;
        }

        // The HAL wire carries the category as `_links.category` and never as
        // a direct member, so the HAL item schema must not inherit the plain
        // `category` property. It is rebuilt from the plain `Subscription`
        // schema in this same document, so new members are picked up without
        // duplicating the contract here.
        $plain = $this->asArray($schemas['Subscription']);
        $properties = $plain['properties'] ?? [];
        unset($properties['category']);

        $item = [
            'type' => 'object',
            'properties' => ['_links' => [
                'type' => 'object',
                'properties' => [
                    'self' => self::link(),
                    'category' => self::link(),
                ],
            ]] + $properties,
        ];
        if (isset($plain['required'])) {
            $item['required'] = array_values(array_diff($plain['required'], ['category']));
        }
        if (isset($plain['description'])) {
            $item['description'] = $plain['description'];
        }

        $this->writeBack($schemas, 'Subscription.jsonhal', $item);
    }

    private function fixCollectionResponses(OpenApi $openApi): void
    {
        foreach ($openApi->getPaths()->getPaths() as $pathItem) {
            foreach (self::operations($pathItem) as $operation) {
                foreach ($operation->getResponses() ?? [] as $response) {
                    $content = $response->getContent();
                    if (null === $content || !isset($content[self::HAL])) {
                        continue;
                    }
                    /** @var MediaType $media */
                    $media = $content[self::HAL];
                    $schema = $media->getSchema();
                    if (null === $schema) {
                        continue;
                    }
                    $this->fixCollectionResponseSchema($schema);
                }
            }
        }
    }

    /**
     * @param \ArrayObject<string, mixed> $schema
     */
    private function fixCollectionResponseSchema(\ArrayObject $schema): void
    {
        $resolved = $this->asArray($schema);
        $ref = $resolved['allOf'][0]['$ref'] ?? null;
        if (!\is_string($ref)
            || !str_ends_with($ref, 'HalCollectionBaseSchema')
            && !str_ends_with($ref, 'HalCollectionBaseSchemaNoPagination')
        ) {
            return;
        }

        // WORKAROUND (upstream, same mismatch): the generated response schema
        // refines `_embedded` through `additionalProperties`, leaving the item
        // array unnamed. Name it `item` and type it with the HAL item schema.
        // Removable once upstream emits the named array itself.
        $itemRef = $resolved['allOf'][1]['properties']['_embedded']['additionalProperties']['items'] ?? null;
        if (!\is_array($itemRef)) {
            return;
        }
        $resolved['allOf'][1]['properties']['_embedded'] = [
            'type' => 'object',
            'properties' => ['item' => ['type' => 'array', 'items' => $itemRef]],
        ];
        $schema->exchangeArray($resolved);
    }

    /**
     * @return array{href: array{type: string, format: string}}
     */
    private static function link(): array
    {
        return ['type' => 'object', 'properties' => ['href' => ['type' => 'string', 'format' => 'iri-reference']]];
    }

    /**
     * @return array{type: string, items: array<string, mixed>}
     */
    private static function linkArray(): array
    {
        return ['type' => 'array', 'items' => self::link()];
    }

    /**
     * @return iterable<Operation>
     */
    private static function operations(PathItem $pathItem): iterable
    {
        foreach (['getGet', 'getPut', 'getPost', 'getDelete', 'getPatch'] as $getter) {
            $operation = $pathItem->{$getter}();
            if ($operation instanceof Operation) {
                yield $operation;
            }
        }
    }

    /**
     * Component and media-type schemas are either plain arrays or
     * ArrayObject models; normalize to a plain array for patching.
     *
     * @param mixed $schema
     *
     * @return array<string, mixed>
     */
    private function asArray(mixed $schema): array
    {
        if ($schema instanceof \ArrayObject) {
            /** @var array<string, mixed> $copy */
            $copy = $schema->getArrayCopy();

            return $copy;
        }

        \assert(\is_array($schema));

        return $schema;
    }

    /**
     * @param \ArrayObject<string, mixed> $schemas
     * @param array<string, mixed>        $value
     */
    private function writeBack(\ArrayObject $schemas, string $key, array $value): void
    {
        $current = $schemas[$key];
        if ($current instanceof \ArrayObject) {
            $current->exchangeArray($value);

            return;
        }
        $schemas[$key] = $value;
    }
}
