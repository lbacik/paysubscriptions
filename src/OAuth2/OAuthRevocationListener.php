<?php

declare(strict_types=1);

namespace App\OAuth2;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use League\Bundle\OAuth2ServerBundle\Model\AbstractClient;

/**
 * Revokes refresh-token families when account or client state changes
 * (issue #93).
 *
 * A password write (change, reset, or hash upgrade) and a client
 * deactivation or removal all flow through the entity manager, so a single
 * flush listener catches every ORM path — including future ones that persist
 * through the entity manager — without each caller remembering to revoke:
 *
 * - `onFlush` only collects: Users whose `password` field changed and client
 *   identifiers that were deactivated (`active` true → false) or deleted;
 * - `postFlush` revokes through RefreshFamilyRevoker. Acting after the flush
 *   keeps the in-flight UnitOfWork untouched; the revoker's own flush only
 *   touches family, link, and bundle token rows, which never re-trigger this
 *   listener, so the cycle terminates.
 *
 * User deletion is deliberately not handled here: AccountDeletionService
 * removes grants and token records explicitly, and the remaining family rows
 * cascade with the User row.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class OAuthRevocationListener
{
    /**
     * @var array<int, User>
     */
    private array $passwordChangedUsers = [];

    /**
     * @var array<string, true>
     */
    private array $pendingClientIds = [];

    public function __construct(
        private readonly RefreshFamilyRevoker $revoker,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof User && $this->passwordChanged($unitOfWork, $entity)) {
                $this->passwordChangedUsers[spl_object_id($entity)] = $entity;
            }

            if ($entity instanceof AbstractClient && $this->deactivated($unitOfWork, $entity)) {
                $this->pendingClientIds[$entity->getIdentifier()] = true;
            }
        }

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof AbstractClient) {
                // A removed identifier must not resurrect its families when a
                // client is later re-created under the same identifier.
                $this->pendingClientIds[$entity->getIdentifier()] = true;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->passwordChangedUsers && [] === $this->pendingClientIds) {
            return;
        }

        // Drain first: the revoker flushes, which re-enters this listener, and
        // the nested pass must observe empty collections.
        $users = $this->passwordChangedUsers;
        $clientIds = array_keys($this->pendingClientIds);
        $this->passwordChangedUsers = [];
        $this->pendingClientIds = [];

        foreach ($users as $user) {
            $this->revoker->revokeForUser($user);
        }

        foreach ($clientIds as $clientId) {
            $this->revoker->revokeForClientId($clientId);
        }
    }

    private function passwordChanged(UnitOfWork $unitOfWork, User $user): bool
    {
        $changeSet = $unitOfWork->getEntityChangeSet($user);

        return \array_key_exists('password', $changeSet)
            && $changeSet['password'][0] !== $changeSet['password'][1];
    }

    private function deactivated(UnitOfWork $unitOfWork, AbstractClient $client): bool
    {
        $changeSet = $unitOfWork->getEntityChangeSet($client);

        return \array_key_exists('active', $changeSet)
            && true === $changeSet['active'][0]
            && false === $changeSet['active'][1];
    }
}
