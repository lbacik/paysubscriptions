<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Api\Problem;
use App\Entity\ExpenseCategory;
use App\Entity\User;
use App\Repository\ExpenseCategoryRepository;
use App\Service\ExpenseCategoryService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * ExpenseCategory endpoints for API v1.
 *
 * Reads (issue #89) plus collection POST and detail PATCH/DELETE. Collection
 * and detail are scoped to the bearer token User, ordered by name, and
 * returned as ordinary `application/json`. Writes accept `name` and `color`
 * only — every other field is ignored — and reuse the entity constraints and
 * service rules already enforced by the web app: name length, `#RRGGBB`
 * color, per-User name uniqueness (409 on collision, including a lost
 * DB race), same-User ownership, and no silent reassignment on delete (409
 * while any Subscription uses the category). Missing, malformed, or foreign
 * IDs return 404 `application/problem+json` without disclosing another
 * User's data; constraint violations return 422 with field-level `errors`.
 */
#[Route('/api/v1/expense-categories')]
class ExpenseCategoryController extends AbstractController
{
    public function __construct(
        private readonly ExpenseCategoryRepository $categories,
        private readonly ExpenseCategoryService $service,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'api_v1_expense_categories_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        $rows = $this->categories->findBy(['owner' => $user], ['name' => 'ASC']);

        return new JsonResponse(array_map($this->serialize(...), $rows), Response::HTTP_OK);
    }

    #[Route('', name: 'api_v1_expense_categories_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        $data = $this->decodeJson($request);
        if (null === $data) {
            return $this->validationFailed('Request body must be a JSON object.');
        }

        $errors = $this->validateFields($data, true);
        if ([] !== $errors) {
            return $this->validationFailed('The given data did not pass validation.', $errors);
        }

        /** @var string $name */
        $name = $data['name'];
        /** @var string $color */
        $color = $data['color'];

        $clash = $this->nameClashResponse($user, $name);
        if (null !== $clash) {
            return $clash;
        }

        try {
            $category = $this->service->create($user, $name, $color);
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent request creating the same name
            // for this User: report the collision instead of 500ing.
            return $this->nameConflict($name);
        }

        return new JsonResponse($this->serialize($category), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_v1_expense_categories_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        $category = $this->findOwned($user, $id);
        if (null === $category) {
            return $this->categoryNotFound();
        }

        return new JsonResponse($this->serialize($category), Response::HTTP_OK);
    }

    #[Route('/{id}', name: 'api_v1_expense_categories_update', methods: ['PATCH'])]
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        $category = $this->findOwned($user, $id);
        if (null === $category) {
            return $this->categoryNotFound();
        }

        $data = $this->decodeJson($request);
        if (null === $data) {
            return $this->validationFailed('Request body must be a JSON object.');
        }

        $suppliesName = \array_key_exists('name', $data);
        $suppliesColor = \array_key_exists('color', $data);
        if (!$suppliesName && !$suppliesColor) {
            return $this->validationFailed('No updatable fields supplied. Send "name" and/or "color".');
        }

        // PATCH changes only supplied fields: the rest keep their values.
        $candidate = [
            'name' => $suppliesName ? $data['name'] : $category->getName(),
            'color' => $suppliesColor ? $data['color'] : $category->getColor(),
        ];
        $errors = $this->validateFields($candidate, false);
        if ([] !== $errors) {
            return $this->validationFailed('The given data did not pass validation.', $errors);
        }

        /** @var string $name */
        $name = $candidate['name'];
        /** @var string $color */
        $color = $candidate['color'];

        $clash = $this->nameClashResponse($user, $name, $category);
        if (null !== $clash) {
            return $clash;
        }

        try {
            $this->service->update($category, $name, $color);
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent request taking the same name for
            // this User: report the collision instead of 500ing.
            return $this->nameConflict($name);
        }

        return new JsonResponse($this->serialize($category), Response::HTTP_OK);
    }

    #[Route('/{id}', name: 'api_v1_expense_categories_delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        $category = $this->findOwned($user, $id);
        if (null === $category) {
            return $this->categoryNotFound();
        }

        try {
            $this->service->delete($category);
        } catch (\LogicException $exception) {
            // The category is still used by Subscriptions: refuse without
            // reassigning anything, mirroring the web app.
            return new JsonResponse(
                Problem::body(
                    Problem::CATEGORY_IN_USE,
                    'Expense category in use',
                    Response::HTTP_CONFLICT,
                    $exception->getMessage(),
                ),
                Response::HTTP_CONFLICT,
                ['Content-Type' => 'application/problem+json'],
            );
        }

        return new Response('', Response::HTTP_NO_CONTENT);
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
     * Returns the 409 response when `$name` is already used by `$user`,
     * ignoring `$ignore` itself so PATCH keeping its own name is not a
     * collision. Returns null when the name is free.
     */
    private function nameClashResponse(User $user, string $name, ?ExpenseCategory $ignore = null): ?JsonResponse
    {
        $clash = $this->findByOwnerAndName($user, $name);
        if (null !== $clash && (null === $ignore || (string) $clash->getId() !== (string) $ignore->getId())) {
            return $this->nameConflict($name);
        }

        return null;
    }

    private function findByOwnerAndName(User $user, string $name): ?ExpenseCategory
    {
        return $this->categories->findOneBy(['owner' => $user, 'name' => $name]);
    }

    /**
     * Decodes a JSON object body. Returns null when the body is not a JSON
     * object (malformed JSON, empty body, or a JSON scalar/array).
     *
     * @return array<string, mixed>|null
     */
    private function decodeJson(Request $request): ?array
    {
        $content = trim($request->getContent());
        if ('' === $content || str_starts_with($content, '[')) {
            return null;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        // `{}` decodes to `[]`, which is a valid (field-less) payload here;
        // every top-level JSON array was rejected by the `[` guard above.
        return \is_array($data) ? $data : null;
    }

    /**
     * Validates the writable fields (`name`, `color`) of a create/update
     * payload against the same entity constraints the web app enforces.
     * Missing (null) values are validated as blank so violations point at
     * the field; non-string scalars can never satisfy the constraints and
     * get a type violation directly.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{field: string, message: string}>
     */
    private function validateFields(array $data, bool $isCreate): array
    {
        $errors = [];
        $probeValues = [];

        foreach (['name', 'color'] as $field) {
            $present = \array_key_exists($field, $data);
            $value = $present ? $data[$field] : null;

            if (!$present && !$isCreate) {
                continue;
            }

            if (null === $value) {
                $probeValues[$field] = '';
            } elseif (!\is_string($value)) {
                $errors[] = ['field' => $field, 'message' => 'This value should be of type string.'];

                continue;
            } else {
                $probeValues[$field] = $value;
            }
        }

        if ([] !== $probeValues) {
            $probe = (new ExpenseCategory())
                ->setName($probeValues['name'] ?? 'valid placeholder')
                ->setColor($probeValues['color'] ?? '#000000');

            foreach ($this->validator->validate($probe) as $violation) {
                $field = ltrim((string) $violation->getPropertyPath(), '.');
                if (!\array_key_exists($field, $probeValues)) {
                    continue;
                }
                $errors[] = ['field' => $field, 'message' => (string) $violation->getMessage()];
            }
        }

        // Deterministic field order for the contract.
        usort($errors, static fn (array $a, array $b): int => [$a['field'], $a['message']] <=> [$b['field'], $b['message']]);

        return $errors;
    }

    private function unauthorized(): JsonResponse
    {
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

    private function categoryNotFound(): JsonResponse
    {
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

    private function nameConflict(string $name): JsonResponse
    {
        return new JsonResponse(
            Problem::body(
                Problem::CATEGORY_NAME_CONFLICT,
                'Expense category name already exists',
                Response::HTTP_CONFLICT,
                sprintf('You already have an expense category named "%s".', $name),
            ),
            Response::HTTP_CONFLICT,
            ['Content-Type' => 'application/problem+json'],
        );
    }

    /**
     * @param list<array{field: string, message: string}> $errors
     */
    private function validationFailed(string $detail, array $errors = []): JsonResponse
    {
        $extra = [] !== $errors ? ['errors' => $errors] : [];

        return new JsonResponse(
            Problem::body(
                Problem::VALIDATION_FAILED,
                'Validation failed',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $detail,
                $extra,
            ),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['Content-Type' => 'application/problem+json'],
        );
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
