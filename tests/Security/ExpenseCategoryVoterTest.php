<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ExpenseCategory;
use App\Entity\User;
use App\Security\ExpenseCategoryVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class ExpenseCategoryVoterTest extends TestCase
{
    private ExpenseCategoryVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new ExpenseCategoryVoter();
    }

    private function tokenFor(mixed $user): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    private function ownedCategory(string $ownerEmail = 'owner@example.com'): ExpenseCategory
    {
        return (new ExpenseCategory())
            ->setOwner((new User())->setEmail($ownerEmail))
            ->setName('Food')
            ->setColor('#ff0000');
    }

    private function user(string $email): User
    {
        return (new User())->setEmail($email);
    }

    /**
     * @dataProvider protectedAttributeProvider
     */
    public function testOwnerIsGranted(string $attribute): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->user('owner@example.com')), $this->ownedCategory(), [$attribute])
        );
    }

    public function protectedAttributeProvider(): iterable
    {
        yield 'view' => [ExpenseCategoryVoter::VIEW];
        yield 'edit' => [ExpenseCategoryVoter::EDIT];
        yield 'delete' => [ExpenseCategoryVoter::DELETE];
    }

    /**
     * @dataProvider protectedAttributeProvider
     */
    public function testOtherUserIsDenied(string $attribute): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->user('intruder@example.com')), $this->ownedCategory(), [$attribute])
        );
    }

    /**
     * @dataProvider protectedAttributeProvider
     */
    public function testAnonymousIsDenied(string $attribute): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor(null), $this->ownedCategory(), [$attribute])
        );
    }

    public function testUnsupportedAttributeAbstains(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->tokenFor($this->user('owner@example.com')), $this->ownedCategory(), ['ROLE_USER'])
        );
    }

    public function testUnsupportedSubjectAbstains(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->tokenFor($this->user('owner@example.com')), new \stdClass(), [ExpenseCategoryVoter::VIEW])
        );
    }
}
