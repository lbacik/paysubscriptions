<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\OpenApi;
use App\OAuth2\OAuth2Config;

/**
 * Requires the single `api:full` scope on the generated document's global
 * security requirement (issues #247, #260).
 *
 * API Platform's built-in `api_platform.oauth` config already adds the
 * `oauth` authorization-code scheme (with `/authorize`, `/token` and the
 * `api:full` scope) and a global requirement for it, but the requirement
 * carries no scopes. An empty requirement would sign Swagger UI in without
 * requesting a scope, and the issued token would then fail the
 * `ApiAccessListener` gate with 403. Pinning the scope here documents the
 * contract the listener enforces: every operation needs `api:full`.
 */
final class OAuthSecurityDecorator implements OpenApiFactoryInterface
{
    public function __construct(
        private readonly OpenApiFactoryInterface $decorated,
    ) {
    }

    /**
     * @param array{base_url?: string, filter_tags?: string|string[]}&array<string, mixed> $context
     */
    public function __invoke(array $context = []): OpenApi
    {
        return ($this->decorated)($context)->withSecurity([
            ['oauth' => [OAuth2Config::SCOPE_FULL]],
        ]);
    }
}
