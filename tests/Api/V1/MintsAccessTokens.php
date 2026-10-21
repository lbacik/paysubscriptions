<?php

declare(strict_types=1);

namespace App\Tests\Api\V1;

use App\OAuth2\ApiAccessTokenEntity;
use App\OAuth2\OAuth2Config;
use DateTimeImmutable;
use League\Bundle\OAuth2ServerBundle\Entity\Client as ClientEntity;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use League\OAuth2\Server\CryptKey;

/**
 * Mints API v1 access tokens in-process, signed with the test keypair in
 * tests/Fixtures/oauth exactly as the authorization server signs issued
 * tokens (TokenGateTest proves one issued through the real OAuth2 flow opens
 * the API too). Every claim the gate checks can be varied per token.
 */
trait MintsAccessTokens
{
    protected const CLIENT_ID = 'paysubs-cli';
    protected const ISSUER = 'http://localhost';

    protected function craftToken(
        ?string $userIdentifier,
        string $issuer = self::ISSUER,
        string $audience = OAuth2Config::API_AUDIENCE,
        ?DateTimeImmutable $expiry = null,
        string $scope = OAuth2Config::SCOPE_FULL,
        string $clientId = self::CLIENT_ID,
    ): string {
        $entity = new ApiAccessTokenEntity($issuer, $audience);
        $entity->setIdentifier(bin2hex(random_bytes(16)));
        $clientEntity = new ClientEntity();
        $clientEntity->setIdentifier($clientId);
        $clientEntity->setName('PaySubscriptions CLI');
        $entity->setClient($clientEntity);
        if (null !== $userIdentifier) {
            $entity->setUserIdentifier($userIdentifier);
        }
        $entity->addScope(new AccessTokenScope($scope));
        $entity->setExpiryDateTime($expiry ?? new DateTimeImmutable('+15 minutes'));
        $entity->setPrivateKey(new CryptKey(static::getContainer()->getParameter('kernel.project_dir').'/tests/Fixtures/oauth/private.pem'));

        return $entity->toString();
    }

    /**
     * Registers the public CLI client approved for `api:full`, which
     * ClientApprovalPolicy requires before any token opens API v1.
     */
    protected function registerPublicClient(): void
    {
        $manager = static::getContainer()->get(ClientManagerInterface::class);

        $client = new Client('PaySubscriptions CLI', self::CLIENT_ID, null);
        $client->setRedirectUris(new RedirectUri('http://127.0.0.1/callback'));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(new Scope(OAuth2Config::SCOPE_FULL));
        $manager->save($client);
    }
}
