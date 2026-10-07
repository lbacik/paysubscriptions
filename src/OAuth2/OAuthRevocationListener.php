<?php

declare(strict_types=1);

namespace App\OAuth2;

use App\Connection\ConnectionLifecycle;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use League\Bundle\OAuth2ServerBundle\Model\AbstractClient;

/**
 * Routes account and client state changes through the Connection lifecycle
 * module (ADR 0005).
 *
 * A password write (change, reset, or hash upgrade) and a client
 * deactivation or removal all flow through the entity manager, so a single
 * flush listener catches every ORM path — including future ones that persist
 * through the entity manager — without each caller remembering to revoke:
 *
 * - `onFlush` only collects: Users whose `password` field changed, client
 *   identifiers that were deactivated (`active` true → false), and client
 *   identifiers that were deleted;
 * - `postFlush` calls the module. Acting after the flush keeps the in-flight
 *   UnitOfWork untouched; the module's own flush only touches consent,
 *   family, link, bundle token, and authorization-code rows, which never
 *   re-trigger this listener, so the cycle terminates.
 *
 * Deactivation revokes the Client's credentials but keeps its Connections;
 * removal ends all of the Client's Connections, so a Client re-registered
 * under the same identifier starts with no Connections. User deletion is
 * deliberately not handled here: AccountDeletionService removes grants and
 * token records explicitly, and the remaining family rows cascade with the
 * User row.
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
    private array $deactivatedClientIds = [];

    /**
     * @var array<string, true>
     */
    private array $removedClientIds = [];

    public function __construct(
        private readonly ConnectionLifecycle $connections,
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
                $this->deactivatedClientIds[$entity->getIdentifier()] = true;
            }
        }

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof AbstractClient) {
                // A removed identifier must not resurrect its families when a
                // client is later re-created under the same identifier.
                $this->removedClientIds[$entity->getIdentifier()] = true;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->passwordChangedUsers && [] === $this->deactivatedClientIds && [] === $this->removedClientIds) {
            return;
        }

        // Drain first: the module flushes, which re-enters this listener, and
        // the nested pass must observe empty collections.
        $users = $this->passwordChangedUsers;
        $deactivated = array_keys($this->deactivatedClientIds);
        $removed = array_keys($this->removedClientIds);
        $this->passwordChangedUsers = [];
        $this->deactivatedClientIds = [];
        $this->removedClientIds = [];

        foreach ($users as $user) {
            $this->connections->revokeCredentialsForUser($user);
        }

        foreach ($deactivated as $clientId) {
            // A client deactivated and removed in the same flush ends its
            // Connections; the stronger removal covers the deactivation.
            if (!\in_array($clientId, $removed, true)) {
                $this->connections->revokeCredentialsForClient($clientId);
            }
        }

        foreach ($removed as $clientId) {
            $this->connections->endConnectionsForClient($clientId);
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
