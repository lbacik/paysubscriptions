<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ExpenseCategory;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Ownership rules without a database: persisted users compare by id, and
 * unpersisted ones only ever equal themselves.
 */
final class ExpenseCategoryOwnershipTest extends TestCase
{
    private function userWithId(?Uuid $id = null): User
    {
        $user = (new User())->setEmail(sprintf('user-%s@example.com', Uuid::v4()->toRfc4122()));

        if (null !== $id) {
            $property = new \ReflectionProperty(User::class, 'id');
            $property->setAccessible(true);
            $property->setValue($user, $id);
        }

        return $user;
    }

    private function categoryOwnedBy(User $owner): ExpenseCategory
    {
        return (new ExpenseCategory())
            ->setName('Food')
            ->setColor('#ff0000')
            ->setOwner($owner);
    }

    public function testPersistedOwnerMatchesDifferentInstanceWithSameId(): void
    {
        $id = Uuid::v4();
        $category = $this->categoryOwnedBy($this->userWithId($id));

        self::assertTrue($category->isOwnedBy($this->userWithId($id)));
    }

    public function testPersistedOwnerRejectsDifferentId(): void
    {
        $category = $this->categoryOwnedBy($this->userWithId(Uuid::v4()));

        self::assertFalse($category->isOwnedBy($this->userWithId(Uuid::v4())));
    }

    public function testOwnerlessCategoryBelongsToNobody(): void
    {
        $category = (new ExpenseCategory())->setName('Food')->setColor('#ff0000');

        self::assertFalse($category->isOwnedBy($this->userWithId(Uuid::v4())));
        self::assertFalse($category->isOwnedBy(new User()));
    }

    public function testUnpersistedOwnerMatchesItself(): void
    {
        $owner = (new User())->setEmail('owner@example.com');
        $category = $this->categoryOwnedBy($owner);

        self::assertTrue($category->isOwnedBy($owner));
    }

    public function testUnpersistedUsersWithSameEmailMatch(): void
    {
        $category = $this->categoryOwnedBy((new User())->setEmail('owner@example.com'));

        self::assertTrue($category->isOwnedBy((new User())->setEmail('owner@example.com')));
        self::assertFalse($category->isOwnedBy((new User())->setEmail('intruder@example.com')));
    }

    public function testUnpersistedUsersWithNoEmailNeverMatch(): void
    {
        $category = $this->categoryOwnedBy(new User());

        // The empty-identifier trap: two email-less users must not compare
        // equal, or any unpersisted user would own every such category.
        self::assertFalse($category->isOwnedBy(new User()));
    }

    public function testSetOwnerAcceptsFirstAndSameOwner(): void
    {
        $owner = $this->userWithId(Uuid::v4());
        $category = (new ExpenseCategory())->setName('Food')->setColor('#ff0000');

        $category->setOwner($owner);
        self::assertSame($owner, $category->getOwner());

        // Same instance again, and a different instance for the same row.
        $category->setOwner($owner);
        $category->setOwner($this->userWithId($owner->getId()));
        self::assertSame($owner->getId(), $category->getOwner()->getId());
    }

    public function testSetOwnerRejectsReparentingToDifferentOwner(): void
    {
        $category = $this->categoryOwnedBy($this->userWithId(Uuid::v4()));

        $this->expectException(\LogicException::class);
        $category->setOwner($this->userWithId(Uuid::v4()));
    }
}
