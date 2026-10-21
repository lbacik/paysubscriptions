<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public Swagger UI over the published v1 OpenAPI contract.
 *
 * API Platform's own docs page is disabled: the API is plain controllers, so
 * its generated spec has no operations. The contract in
 * resources/openapi/v1.json is the single source of truth, and is served
 * without a token here so the page can load it. Calling the API from the UI
 * still requires a Bearer token ("Authorize").
 */
final class ApiDocsController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/api/docs', name: 'api_docs', priority: 10, methods: ['GET'])]
    public function ui(): Response
    {
        return new Response(<<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PaySubscriptions API</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui.css">
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"></script>
<script>
window.ui = SwaggerUIBundle({
    url: '/api/docs.json',
    dom_id: '#swagger-ui',
    persistAuthorization: true,
});
</script>
</body>
</html>
HTML);
    }

    #[Route('/api/docs.json', name: 'api_docs_spec', priority: 10, methods: ['GET'])]
    public function spec(): JsonResponse
    {
        $data = json_decode((string) file_get_contents($this->projectDir.'/resources/openapi/v1.json'), true);

        return new JsonResponse($data);
    }
}
