<?php

declare(strict_types=1);

namespace App\Prototype;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * PROTOTYPE (#246): the 409 domain type from #245. The real version would be
 * thrown by ExpenseCategoryService instead of a bare \LogicException.
 */
final class CategoryInUseProblem extends HttpException implements ProblemExceptionInterface
{
    public function __construct(string $detail, ?\Throwable $previous = null)
    {
        parent::__construct(409, $detail, $previous);
    }

    public function getType(): string
    {
        return '/problems/category-in-use';
    }

    public function getTitle(): ?string
    {
        return 'Expense category in use';
    }

    public function getStatus(): ?int
    {
        return 409;
    }

    public function getDetail(): ?string
    {
        return $this->getMessage();
    }

    public function getInstance(): ?string
    {
        return null;
    }
}
