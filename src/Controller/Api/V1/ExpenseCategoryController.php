<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Api\Problem;
use App\Entity\ExpenseCategory;
use App\Entity\User;
use App\Repository\ExpenseCategoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only ExpenseCategory endpoints for API v1 (issue #89).
 *
 * Collection and detail are scoped to the bearer token User, ordered by name,
 * and returned as ordinary `application/json`. Missing or foreign IDs return
 * 404 `application/problem+json` without disclosing another User's data.
 */
#[Route('/api/v1/expense-categories')]
class ExpenseCategoryController extends AbstractController
{
    public function __construct(
        private readonly ExpenseCategoryRepository $categories,
    ) {
    }

    #[Route('', name: 'api_v1_expense_categories_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(
                Problem::body(
                    Problem::UNAUTHORIZED,
                    'Authentication required',
                    Response::HTTP_UNAUTHORIZED,
                    'Authentication is required to access this resource.',
                ),
                Response::HTTP_UNAUTHORIZED,
                ['Content-Type' => 'application/problem+json'],
            );
        }

        $rows = $this->categories->findBy(['owner' => $user], ['name' => 'ASC']);

        return new JsonResponse(array_map($this->serialize(...), $rows), Response::HTTP_OK);
    }

    #[Route('/{id}', name: 'api_v1_expense_categories_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(
                Problem::body(
                    Problem::UNAUTHORIZED,
                    'Authentication required',
                    Response::HTTP_UNAUTHORIZED,
                    'Authentication is required to access this resource.',
                ),
                Response::HTTP_UNAUTHORIZED,
                ['Content-Type' => 'application/problem+json'],
            );
        }

        $category = $this->findOwned($user, $id);
        if (null === $category) {
            return new JsonResponse(
                Problem::body(
                    Problem::CATEGORY_NOT_FOUND,
                    'Expense category not found',
                    Response::HTTP_NOT_FOUND,
                    'No expense category with this identifier.',
                ),
                Response::HTTP_NOT_FOUND,
                ['Content-Type' => 'application/problem+json'],
            );
        }

        return new JsonResponse($this->serialize($category), Response::HTTP_OK);
    }

    private function findOwned(User $user, string $id): ?ExpenseCategory
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $category = $this->categories->find(Uuid::fromString($id));
        if (null === $category) {
            return null;
        }

        // Foreign IDs collapse to 404 so ownership is never disclosed.
        if (!$category->isOwnedBy($user)) {
            return null;
        }

        return $category;
    }

    /**
     * @return array{id: string, name: string, color: string, createdAt: string, updatedAt: string}
     */
    private function serialize(ExpenseCategory $category): array
    {
        return [
            'id' => (string) $category->getId(),
            'name' => $category->getName(),
            'color' => $category->getColor(),
            'createdAt' => $category->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $category->getUpdatedAt()->format(DATE_ATOM),
        ];
    }
}
