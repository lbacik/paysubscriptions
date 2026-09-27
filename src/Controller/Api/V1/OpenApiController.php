<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the published v1 OpenAPI contract (issue #89, decision #87).
 *
 * The document is versioned with the implementation: changes to routes,
 * schemas, security, or errors are reviewed as contract changes. Served as
 * ordinary `application/json` behind the same bearer boundary as resources.
 */
final class OpenApiController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/api/v1/openapi.json', name: 'api_v1_openapi', methods: ['GET'])]
    public function show(): JsonResponse
    {
        $path = $this->projectDir.'/resources/openapi/v1.json';
        $data = json_decode((string) file_get_contents($path), true);

        return new JsonResponse($data, Response::HTTP_OK);
    }
}
