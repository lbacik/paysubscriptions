<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Documentation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

class DocsController extends AbstractController
{
    #[Route('/docs', name: 'app_docs')]
    public function index(
        Documentation $documentation,
        #[MapQueryParameter] string $section = 'about'
    ): Response {

        return $this->render('docs/index.html.twig', [
            'documentation' => $documentation,
            'show' => ucfirst($section),
        ]);
    }


}
