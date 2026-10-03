<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecipientRestriction;
use App\Enum\RecipientRestrictionState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<RecipientRestriction>
 */
class RecipientRestrictionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecipientRestriction::class);
    }

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Records a restriction without ever loosening one.
     *
     * A single upsert keeps concurrent and duplicate feedback idempotent:
     * DoNotSend replaces Undeliverable, nothing replaces DoNotSend, and a
     * repeated state leaves the row untouched.
     */
    public function restrict(string $email, RecipientRestrictionState $state): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // MySQL applies the assignments left to right, so updated_at compares against the old state.
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO recipient_restriction (id, email, state, created_at, updated_at) VALUES (:id, :email, :state, :now, :now)
             ON DUPLICATE KEY UPDATE
                updated_at = IF(state <> :strongest AND VALUES(state) = :strongest, VALUES(updated_at), updated_at),
                state = IF(VALUES(state) = :strongest, :strongest, state)',
            [
                'id' => Uuid::v7()->toBinary(),
                'email' => self::normalize($email),
                'state' => $state->value,
                'now' => $now,
                'strongest' => RecipientRestrictionState::DoNotSend->value,
            ],
        );
    }

    /**
     * Returns the normalized addresses among $emails that must not receive mail.
     *
     * @param list<string> $emails
     *
     * @return list<string>
     */
    public function restrictedAmong(array $emails): array
    {
        if ([] === $emails) {
            return [];
        }

        return array_values(array_map('strval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT email FROM recipient_restriction WHERE email IN (:emails)',
            ['emails' => array_values(array_unique(array_map(self::normalize(...), $emails)))],
            ['emails' => ArrayParameterType::STRING],
        )));
    }

    public function findOneByEmail(string $email): ?RecipientRestriction
    {
        return $this->findOneBy(['email' => self::normalize($email)]);
    }
}
