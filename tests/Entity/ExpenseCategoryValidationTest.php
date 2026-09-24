<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ExpenseCategory;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ExpenseCategoryValidationTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    private function createCategory(?string $name, ?string $color): ExpenseCategory
    {
        $category = (new ExpenseCategory())
            ->setOwner((new User())->setEmail('owner@example.com'))
            ->setName($name ?? '')
            ->setColor($color ?? '');

        // Bypass setters for null cases to exercise blank rejection below.
        if (null === $name) {
            $property = new \ReflectionProperty(ExpenseCategory::class, 'name');
            $property->setAccessible(true);
            $property->setValue($category, null);
        }

        if (null === $color) {
            $property = new \ReflectionProperty(ExpenseCategory::class, 'color');
            $property->setAccessible(true);
            $property->setValue($category, null);
        }

        return $category;
    }

    public function testValidCategoryHasNoViolations(): void
    {
        $category = $this->createCategory('Subscriptions', '#577399');

        self::assertCount(0, $this->validator->validateProperty($category, 'name'));
        self::assertCount(0, $this->validator->validateProperty($category, 'color'));
    }

    public function testBlankNameIsRejected(): void
    {
        self::assertGreaterThan(0, \count($this->validator->validateProperty($this->createCategory('', '#577399'), 'name')));
        self::assertGreaterThan(0, \count($this->validator->validateProperty($this->createCategory(null, '#577399'), 'name')));
    }

    public function testTooShortNameIsRejected(): void
    {
        self::assertGreaterThan(0, \count($this->validator->validateProperty($this->createCategory('X', '#577399'), 'name')));
    }

    public function testTooLongNameIsRejected(): void
    {
        self::assertGreaterThan(
            0,
            \count($this->validator->validateProperty($this->createCategory(str_repeat('a', 101), '#577399'), 'name'))
        );
    }

    /**
     * @dataProvider invalidColorProvider
     */
    public function testInvalidColorIsRejected(?string $color): void
    {
        self::assertGreaterThan(
            0,
            \count($this->validator->validateProperty($this->createCategory('Food', $color), 'color')),
            sprintf('Color %s should be rejected.', var_export($color, true))
        );
    }

    public function invalidColorProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'named color' => ['red'];
        yield 'missing hash' => ['577399'];
        yield 'short hex' => ['#fff'];
        yield 'non-hex digit' => ['#gggggg'];
        yield 'too long' => ['#57739900'];
    }

    /**
     * @dataProvider validColorProvider
     */
    public function testValidColorIsAccepted(string $color): void
    {
        self::assertCount(0, $this->validator->validateProperty($this->createCategory('Food', $color), 'color'));
    }

    public function validColorProvider(): iterable
    {
        yield 'lowercase' => ['#577399'];
        yield 'uppercase' => ['#FE5F55'];
        yield 'mixed' => ['#aAbBcC'];
        yield 'black' => ['#000000'];
    }
}
