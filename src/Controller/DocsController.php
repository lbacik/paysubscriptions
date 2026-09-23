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
    #[Route('/about', name: 'app_docs')]
    public function index(
        Documentation $documentation,
        #[MapQueryParameter] string $section = 'about',
        #[MapQueryParameter] ?string $variant = null
    ): Response {
        if ($this->getParameter('kernel.environment') === 'dev'
            && strtolower($section) === 'about'
            && in_array($variant, ['A', 'B', 'C'], true)) {
            return $this->render('docs/about_prototype.html.twig', ['variant' => $variant]);
        }

        return $this->render('docs/index.html.twig', [
            'documentation' => $documentation,
            'show' => ucfirst($section),
        ]);
    }
}
