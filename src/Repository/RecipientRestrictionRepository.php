<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecipientRestriction;
use App\Enum\RecipientRestrictionState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
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
            'INSERT INTO recipient_restriction (id, email, state, created_at, updated_at) VALUES (:id, :email, :state, :now, :now) AS incoming
             ON DUPLICATE KEY UPDATE
                updated_at = IF(recipient_restriction.state <> :strongest AND incoming.state = :strongest, incoming.updated_at, recipient_restriction.updated_at),
                state = IF(incoming.state = :strongest, :strongest, recipient_restriction.state)',
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

    /**
     * Deletes the restriction of $email and returns it as it was, or null when there is none.
     *
     * The row is locked before it is read, so the returned state is the one
     * actually removed even while SES feedback is being recorded concurrently.
     * $beforeCommit runs inside the transaction once the row is found: if it
     * throws, nothing is deleted, so a clear cannot outlive its audit record.
     * Only the operator command app:ses:clear-recipient-restriction calls this.
     *
     * @param callable(RecipientRestriction): void $beforeCommit
     */
    public function clear(string $email, callable $beforeCommit): ?RecipientRestriction
    {
        return $this->getEntityManager()->wrapInTransaction(function (EntityManagerInterface $em) use ($email, $beforeCommit): ?RecipientRestriction {
            $restriction = $this->createQueryBuilder('r')
                ->where('r.email = :email')
                ->setParameter('email', self::normalize($email))
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getOneOrNullResult();

            if ($restriction instanceof RecipientRestriction) {
                $em->remove($restriction);
                $beforeCommit($restriction);
            }

            return $restriction;
        });
    }

    public function findOneByEmail(string $email): ?RecipientRestriction
    {
        return $this->findOneBy(['email' => self::normalize($email)]);
    }
}
