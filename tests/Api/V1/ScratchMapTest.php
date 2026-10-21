<?php

declare(strict_types=1);

namespace App\Tests\Api\V1;

use App\ApiResource\Subscription as SubscriptionDto;
use App\Entity\Subscription as SubscriptionEntity;
use App\Service\ExpenseCategoryService;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

final class ScratchMapTest extends ApiV1TestCase
{
    public function testMap(): void
    {
        $user = $this->createUser('scratch-map@example.com');
        $sub = $this->createSubscription($user);
        /** @var ObjectMapperInterface $mapper */
        $mapper = static::getContainer()->get(ObjectMapperInterface::class);

        try {
            $dto = $mapper->map($sub, SubscriptionDto::class);
            fwrite(STDERR, "\nMAP OK: ".get_debug_type($dto->category)."\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, "\nMAP FAIL: ".get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");
        }

        // And back: DTO -> entity
        $dto2 = new SubscriptionDto();
        $dto2->name = 'X';
        try {
            $entity = $mapper->map($dto2, SubscriptionEntity::class);
            fwrite(STDERR, "\nBACKMAP OK\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, "\nBACKMAP FAIL: ".get_class($e).': '.$e->getMessage()."\n".substr($e->getTraceAsString(), 0, 3000)."\n");
        }

        self::assertTrue(true);
    }
}
