<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExpenseCategory;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExpenseCategory>
 */
class ExpenseCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExpenseCategory::class);
    }

    public function save(ExpenseCategory $category): void
    {
        $category->setUpdatedAt(new DateTime());

        $this->getEntityManager()->persist($category);
        $this->getEntityManager()->flush();
    }

    public function remove(ExpenseCategory $category): void
    {
        $this->getEntityManager()->remove($category);
        $this->getEntityManager()->flush();
    }
}
