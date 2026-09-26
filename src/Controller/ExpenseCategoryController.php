<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ExpenseCategory;
use App\Form\ExpenseCategoryType;
use App\Security\ExpenseCategoryVoter;
use App\Service\ExpenseCategoryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/category')]
class ExpenseCategoryController extends AbstractController
{
    public function __construct(
        private readonly ExpenseCategoryService $categoryService,
    ) {
    }

    #[Route('', name: 'app_category_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('category/index.html.twig', [
            'categories' => $this->categoryService->getForOwner($this->getUser()),
        ]);
    }

    #[Route('/new', name: 'app_category_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof \App\Entity\User);

        $category = new ExpenseCategory();
        $category->setOwner($user);
        $form = $this->createForm(
            ExpenseCategoryType::class,
            $category,
            ['action' => $this->generateUrl('app_category_new')]
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->categoryService->create($user, (string) $category->getName(), (string) $category->getColor());

            $this->addFlash('success', 'Category created successfully');

            return $this->redirectToRoute('app_category_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('category/new.html.twig', [
            'category' => $category,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_category_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, ExpenseCategory $category): Response
    {
        $this->denyAccessUnlessGranted(ExpenseCategoryVoter::EDIT, $category);

        $form = $this->createForm(
            ExpenseCategoryType::class,
            $category,
            ['action' => $this->generateUrl('app_category_edit', ['id' => $category->getId()])]
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->categoryService->rename($category, (string) $category->getName());
            $this->categoryService->recolor($category, (string) $category->getColor());

            $this->addFlash('success', 'Category updated successfully');

            return $this->redirectToRoute('app_category_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('category/edit.html.twig', [
            'category' => $category,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_category_delete', methods: ['GET', 'POST'])]
    public function delete(Request $request, ExpenseCategory $category): Response
    {
        $this->denyAccessUnlessGranted(ExpenseCategoryVoter::DELETE, $category);

        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid('delete' . $category->getId(), $request->getPayload()->get('_token'))) {
                try {
                    $this->categoryService->delete($category);
                    $this->addFlash('success', 'Category deleted successfully');
                } catch (\LogicException $exception) {
                    $this->addFlash('danger', $exception->getMessage());
                }

                return $this->redirectToRoute('app_category_index', [], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('category/delete.html.twig', [
            'category' => $category,
        ]);
    }
}
