<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\OAuthConsent;
use App\Entity\OAuthRefreshFamily;
use App\Entity\OAuthRefreshFamilyToken;
use App\Entity\User;
use App\OAuth2\ApiAccessTokenEntity;
use App\OAuth2\OAuth2Config;
use App\Service\AccountDeletionService;
use App\Tests\DatabaseTestCase;
use DateTimeImmutable;
use League\Bundle\OAuth2ServerBundle\Entity\Client as ClientEntity;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AuthorizationCode;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * User disconnect, client-facing RFC 7009 revocation, and lifecycle
 * revocation of refresh-token families (issues #92, #93).
 *
 * A User sees their approved clients and revokes each grant; a later
 * authorization asks for consent again and refresh fails. A client revokes
 * its refresh token through the authenticated revocation endpoint, which
 * retires the whole usable family but never claims to revoke self-contained
 * access tokens: already-issued access stays usable until its 15-minute
 * expiry.
 *
 * Changes that should end delegated access invalidate the affected families
 * promptly while the independent web session keeps its own behavior:
 * - changing or resetting a User password revokes that User's families;
 * - disabling a client revokes its families (re-enabling does not restore);
 * - deleting a client revokes its families (re-creating the id does not restore);
 * - deleting a User removes grants and token records;
 * - API requests resolve an existing, eligible User;
 * - web logout ends the web session without disconnecting the CLI.
 *
 * Every revocation asserts isolation: unrelated Users and clients keep access.
 */
final class OAuthRevocationTest extends DatabaseTestCase
{
    private const CLIENT_ID = 'paysubs-cli';
    private const CLIENT_NAME = 'PaySubscriptions CLI';
    private const OTHER_CLIENT_ID = 'other-cli';
    private const OTHER_CLIENT_NAME = 'Other CLI';
    private const REGISTERED_REDIRECT = 'http://127.0.0.1/callback';
    private const REDIRECT_URI = 'http://127.0.0.1:54123/callback';
    private const ISSUER = 'http://localhost';

    private User $user;
    private string $verifier;
    private string $challenge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser('revocation-user@example.com', 'Fixture-Password-1', true);
        $this->registerPublicClient();

        $this->verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $this->challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
    }

    public function testConnectedAppsListShowsApprovedClient(): void
    {
        $this->client->loginUser($this->user);
        $this->authorizeAndExchange();

        $crawler = $this->client->request('GET', '/profile/connected-apps');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::CLIENT_NAME, $crawler->text(null, true));
        self::assertStringContainsString(self::CLIENT_ID, $crawler->text(null, true));
    }

    public function testConnectedAppsPageStatesResidualAccessWindow(): void
    {
        $this->client->loginUser($this->user);
        $this->authorizeAndExchange();

        $crawler = $this->client->request('GET', '/profile/connected-apps');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('15 minutes', $crawler->text(null, true));
    }

    public function testAnonymousUserIsSentThroughWebLogin(): void
    {
        $this->client->request('GET', '/profile/connected-apps');

        self::assertResponseRedirects('/login');
    }

    public function testRevokeDisconnectAsksConsentAgain(): void
    {
        $this->client->loginUser($this->user);
        $this->authorizeAndExchange();

        $this->revokeGrant(self::CLIENT_ID);

        // The remembered grant is gone: authorizing again shows the screen.
        $this->client->request('GET', $this->authorizeUrl());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::CLIENT_NAME, (string) $this->client->getResponse()->getContent());
    }

    public function testRefreshFailsAfterDisconnect(): void
    {
        $this->client->loginUser($this->user);
        $tokens = $this->authorizeAndExchange();

        $this->revokeGrant(self::CLIENT_ID);

        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $tokens['refresh_token'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());
    }

    public function testFreshAuthorizationWorksAfterDisconnect(): void
    {
        $this->client->loginUser($this->user);
        $tokens = $this->authorizeAndExchange();
        $this->revokeGrant(self::CLIENT_ID);

        // Renewed consent starts a new family that refreshes fine.
        $fresh = $this->authorizeAndExchange();
        self::assertNotSame($tokens['refresh_token'], $fresh['refresh_token']);

        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $fresh['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testAccessTokenStaysUsableAfterDisconnect(): void
    {
        $this->client->loginUser($this->user);
        $tokens = $this->authorizeAndExchange();

        $this->revokeGrant(self::CLIENT_ID);

        // Ordinary disconnect retires refresh families immediately, but the
        // already-issued self-contained access token works until expiry.
        $this->client->request('GET', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$tokens['access_token'],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testRevokeOnlyRemovesOwnGrant(): void
    {
        $other = $this->createUser('revoke-other@example.com', 'Fixture-Password-1', true);
        $this->registerPublicClient(self::OTHER_CLIENT_ID, self::OTHER_CLIENT_NAME);

        $this->client->loginUser($this->user);
        $this->authorizeAndExchange();

        $this->client->loginUser($other);
        $otherTokens = $this->authorizeAndExchange(self::OTHER_CLIENT_ID);

        // Revoking the first User's grant leaves the other User untouched…
        $this->client->loginUser($this->user);
        $this->revokeGrant(self::CLIENT_ID);

        // …and revoking an unknown grant changes nothing for the other User.
        $this->revokeGrant(self::OTHER_CLIENT_ID);

        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::OTHER_CLIENT_ID,
            'refresh_token' => $otherTokens['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testDisconnectRevokesPendingAuthorizationCode(): void
    {
        $this->client->loginUser($this->user);
        // Authorized but never exchanged: the code is still pending.
        $code = $this->authorizeOnly();

        $this->revokeGrant(self::CLIENT_ID);

        $this->exchangeCode($code);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->freshEm()->getRepository(OAuthRefreshFamily::class)->findBy(['clientId' => self::CLIENT_ID]));
    }

    public function testDisconnectLeavesOtherPendingAuthorizationCodesAlone(): void
    {
        $other = $this->createUser('pending-other@example.com', 'Fixture-Password-1', true);
        $this->registerPublicClient(self::OTHER_CLIENT_ID, self::OTHER_CLIENT_NAME);

        $this->client->loginUser($other);
        $otherUserCode = $this->authorizeOnly();

        $this->client->loginUser($this->user);
        $otherClientCode = $this->authorizeOnly(self::OTHER_CLIENT_ID);
        $this->authorizeOnly();

        $this->revokeGrant(self::CLIENT_ID);

        // Same client, another User: untouched.
        $this->exchangeCode($otherUserCode);
        self::assertResponseIsSuccessful();

        // Same User, another client: untouched.
        $this->exchangeCode($otherClientCode, self::OTHER_CLIENT_ID);
        self::assertResponseIsSuccessful();
    }

    public function testDisconnectWithoutConsentRowStillRevokesFamilyAndPendingCode(): void
    {
        $this->registerPublicClient(self::OTHER_CLIENT_ID, self::OTHER_CLIENT_NAME);

        $this->client->loginUser($this->user);
        $tokens = $this->authorizeAndExchange();
        $code = $this->authorizeOnly();
        // Keeps the Connected apps page (and its CSRF form) available.
        $this->authorizeAndExchange(self::OTHER_CLIENT_ID);

        // Orphan the grant: the consent row is gone, the family and code remain.
        $this->removeConsentRows(self::CLIENT_ID);

        $this->revokeGrant(self::CLIENT_ID);

        $this->refresh($tokens['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $this->exchangeCode($code);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());
    }

    public function testDisconnectWithNothingToRevokeSucceedsWithoutFlash(): void
    {
        $this->registerPublicClient(self::OTHER_CLIENT_ID, self::OTHER_CLIENT_NAME);

        $this->client->loginUser($this->user);
        $this->authorizeAndExchange(self::OTHER_CLIENT_ID);

        $this->revokeGrant(self::CLIENT_ID);

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('[role="alert"]')->count());
    }

    public function testDisconnectOfGrantShowsDisconnectedFlash(): void
    {
        $this->client->loginUser($this->user);
        $this->authorizeAndExchange();

        $this->revokeGrant(self::CLIENT_ID);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('The application was disconnected', $crawler->text(null, true));
    }

    public function testDisconnectOfPendingCodeOnlyShowsDisconnectedFlash(): void
    {
        $this->registerPublicClient(self::OTHER_CLIENT_ID, self::OTHER_CLIENT_NAME);

        $this->client->loginUser($this->user);
        $this->authorizeAndExchange(self::OTHER_CLIENT_ID);
        $this->authorizeOnly();

        $this->removeConsentRows(self::CLIENT_ID);

        $this->revokeGrant(self::CLIENT_ID);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('The application was disconnected', $crawler->text(null, true));
    }

    public function testClientRevocationRetiresWholeFamily(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();
        $second = $this->refresh($first['refresh_token']);
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/revoke', [
            'token' => $second['refresh_token'],
            'token_type_hint' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
        ]);
        self::assertResponseIsSuccessful();

        // The presented token is dead…
        $this->refresh($second['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());

        // …and so is every other usable token of its family.
        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testClientRevocationKeepsConsentAndAutoApproves(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();

        $this->client->request('POST', '/revoke', [
            'token' => $first['refresh_token'],
            'client_id' => self::CLIENT_ID,
        ]);
        self::assertResponseIsSuccessful();

        // The Connection stays: the remembered consent survives the revoked
        // session, so the next authorization auto-approves without showing
        // the consent screen again (ADR 0005).
        self::assertNotNull($this->freshEm()->getRepository(OAuthConsent::class)->findOneBy([
            'clientId' => self::CLIENT_ID,
        ]));
        $this->client->request('GET', $this->authorizeUrl());
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        $code = $this->codeFromRedirect();

        // The auto-approved authorization mints a working family.
        $this->exchangeCode($code);
        self::assertResponseIsSuccessful();
        $fresh = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($fresh);
        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $fresh['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testConsentScreenListsOtherApprovedClients(): void
    {
        $this->registerPublicClient(self::OTHER_CLIENT_ID, self::OTHER_CLIENT_NAME);
        $this->client->loginUser($this->user);
        $this->authorizeAndExchange();

        // Authorizing another client shows the already-approved one beside
        // the new request.
        $crawler = $this->client->request('GET', $this->authorizeUrl(self::OTHER_CLIENT_ID));

        self::assertResponseIsSuccessful();
        $approved = $crawler->filter('#approved-clients li');
        self::assertCount(1, $approved);
        self::assertStringContainsString(self::CLIENT_NAME, $approved->text(null, true));
    }

    public function testRevokeUnknownTokenStillSucceeds(): void
    {
        $this->client->request('POST', '/revoke', [
            'token' => 'not-a-refresh-token',
            'client_id' => self::CLIENT_ID,
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testRevokeWithoutTokenFails(): void
    {
        $this->client->request('POST', '/revoke', [
            'client_id' => self::CLIENT_ID,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_request', (string) $this->client->getResponse()->getContent());
    }

    public function testRevokeWithUnknownClientFails(): void
    {
        $this->client->request('POST', '/revoke', [
            'token' => 'not-a-refresh-token',
            'client_id' => 'unknown-cli',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertStringContainsString('invalid_client', (string) $this->client->getResponse()->getContent());
    }

    public function testRevokeWithWrongClientKeepsTokenUsable(): void
    {
        $this->registerPublicClient(self::OTHER_CLIENT_ID, self::OTHER_CLIENT_NAME);
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();

        $this->client->request('POST', '/revoke', [
            'token' => $first['refresh_token'],
            'client_id' => self::OTHER_CLIENT_ID,
        ]);
        self::assertResponseIsSuccessful();

        $second = $this->refresh($first['refresh_token']);
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('access_token', $second);
    }

    public function testRevokeAccessTokenHintKeepsAccessUsable(): void
    {
        $this->client->loginUser($this->user);
        $tokens = $this->authorizeAndExchange();

        // Self-contained access tokens cannot be revoked: the endpoint
        // succeeds without claiming revocation, and the token still opens
        // the API until its short expiry.
        $this->client->request('POST', '/revoke', [
            'token' => $tokens['access_token'],
            'token_type_hint' => 'access_token',
            'client_id' => self::CLIENT_ID,
        ]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$tokens['access_token'],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testConfidentialClientRevocationNeedsSecret(): void
    {
        $this->registerConfidentialClient();
        $this->client->loginUser($this->user);
        $tokens = $this->authorizeAndExchange('conf-cli');

        // Wrong secret: rejected as unauthorized, token untouched.
        $this->client->request('POST', '/revoke', [
            'token' => $tokens['refresh_token'],
            'client_id' => 'conf-cli',
            'client_secret' => 'wrong-secret',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertStringContainsString('invalid_client', (string) $this->client->getResponse()->getContent());

        $second = $this->refresh($tokens['refresh_token'], 'conf-cli', 'conf-secret');
        self::assertResponseIsSuccessful();

        // Correct secret: revoked.
        $this->client->request('POST', '/revoke', [
            'token' => $second['refresh_token'],
            'client_id' => 'conf-cli',
            'client_secret' => 'conf-secret',
        ]);
        self::assertResponseIsSuccessful();

        $this->refresh($second['refresh_token'], 'conf-cli', 'conf-secret');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testPasswordChangeRevokesRefreshFamilies(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();

        $this->changePassword($this->user->getEmail(), 'Changed-Password-2!');

        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());

        self::assertTrue($this->newestFamilyForUser('revocation-user@example.com')->isRevoked());
    }

    public function testPasswordResetThroughHttpRevokesRefreshFamilies(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();

        $helper = static::getContainer()->get(ResetPasswordHelperInterface::class);
        $token = $helper->generateResetToken($this->freshUserByEmail('revocation-user@example.com'))->getToken();

        $this->client->request('GET', '/reset-password/reset/'.$token);
        $this->client->followRedirect();
        $form = $this->client->getCrawler()->filter('form[name="change_password_form"]')->form([
            'change_password_form[plainPassword][first]' => 'Reset-BatteryStaple99!',
            'change_password_form[plainPassword][second]' => 'Reset-BatteryStaple99!',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/login');

        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());

        // Fresh authorization after the reset issues a working family.
        $this->login('revocation-user@example.com', 'Reset-BatteryStaple99!');
        $second = $this->authorizeAndExchange();
        $third = $this->refresh($second['refresh_token']);
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('access_token', $third);
    }

    public function testPasswordChangeLeavesOtherUsersFamiliesAlone(): void
    {
        $other = $this->createUser('other-user@example.com', 'Fixture-Password-1', true);

        $this->client->loginUser($this->user);
        $mine = $this->authorizeAndExchange();

        $this->client->loginUser($other);
        $theirs = $this->authorizeAndExchange();

        $this->changePassword('revocation-user@example.com', 'Changed-Password-2!');

        $this->refresh($mine['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $renewed = $this->refresh($theirs['refresh_token']);
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('access_token', $renewed);
    }

    public function testDisablingClientRevokesFamiliesAndReenablingDoesNotRestore(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();

        $this->setClientActive(self::CLIENT_ID, false);

        // A disabled client cannot refresh: its families were revoked
        // immediately on deactivation…
        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());

        // …and the family was revoked immediately, so re-enabling the client
        // does not resurrect the CLI session: fresh authorization is required.
        self::assertTrue($this->newestFamilyForUser('revocation-user@example.com')->isRevoked());

        $this->setClientActive(self::CLIENT_ID, true);
        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());
    }

    public function testDisablingClientLeavesOtherClientsFamiliesAlone(): void
    {
        $this->registerPublicClient(self::OTHER_CLIENT_ID, self::OTHER_CLIENT_NAME);

        $this->client->loginUser($this->user);
        $mine = $this->authorizeAndExchange(self::CLIENT_ID);
        $theirs = $this->authorizeAndExchange(self::OTHER_CLIENT_ID);

        $this->setClientActive(self::CLIENT_ID, false);

        $this->refresh($mine['refresh_token'], self::CLIENT_ID);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());

        $renewed = $this->refresh($theirs['refresh_token'], self::OTHER_CLIENT_ID);
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('access_token', $renewed);
    }

    public function testDeletingClientRevokesFamilies(): void
    {
        $this->client->loginUser($this->user);
        $first = $this->authorizeAndExchange();

        // Reboot into a fresh entity manager before removing the client, just
        // like the operator's `delete-client` console command runs in its own
        // process: the exchange request's managed authorization-code entity
        // must not share persistence context with the removal.
        $this->client->request('GET', '/login');

        $manager = static::getContainer()->get(ClientManagerInterface::class);
        $manager->remove($manager->find(self::CLIENT_ID));

        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertTrue($this->newestFamilyForUser('revocation-user@example.com')->isRevoked());

        // Re-creating the same identifier must not resurrect the old family.
        $this->registerPublicClient();
        $this->refresh($first['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());
    }

    public function testDeletingUserRemovesGrantsAndTokenRecords(): void
    {
        $other = $this->createUser('survivor@example.com', 'Fixture-Password-1', true);
        $this->registerPublicClient(self::OTHER_CLIENT_ID, self::OTHER_CLIENT_NAME);

        $this->client->loginUser($this->user);
        $mine = $this->authorizeAndExchange();
        // Leave one authorization code pending (authorized, never exchanged).
        $this->authorizeOnly();

        $this->client->loginUser($other);
        $theirs = $this->authorizeAndExchange(self::OTHER_CLIENT_ID);

        $familyIds = $this->familyIdsForUser('revocation-user@example.com');
        self::assertNotEmpty($familyIds);
        $tokenIds = $this->tokenIdsForFamilies($familyIds);
        self::assertNotEmpty($tokenIds);

        // Delete through the real account flow.
        $this->client->loginUser($this->freshUserByEmail('revocation-user@example.com'));
        $crawler = $this->client->request('GET', '/account/delete');
        $form = $crawler->selectButton('Delete my account permanently')->form();
        $form['confirm']->tick();
        $form['currentPassword'] = 'Fixture-Password-1';
        $this->client->submit($form);
        self::assertResponseRedirects('/');

        $em = $this->freshEm();
        self::assertNull($em->getRepository(User::class)->findOneBy(['email' => 'revocation-user@example.com']));
        self::assertSame([], $em->getRepository(OAuthConsent::class)->findBy(['clientId' => self::CLIENT_ID]));
        self::assertSame([], $em->getRepository(OAuthRefreshFamily::class)->findBy(['clientId' => self::CLIENT_ID]));
        self::assertSame([], $em->getRepository(AuthorizationCode::class)->findBy(['userIdentifier' => 'revocation-user@example.com']));
        foreach ($tokenIds as $tokenId) {
            self::assertNull($em->find(RefreshToken::class, $tokenId), 'Bundle refresh token '.$tokenId.' must be gone.');
        }

        // The deleted User's CLI session is dead…
        $this->refresh($mine['refresh_token']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        // …while the surviving User's grants, families, and CLI session keep working.
        $check = $this->freshEm();
        self::assertCount(1, $check->getRepository(OAuthConsent::class)->findBy(['clientId' => self::OTHER_CLIENT_ID]));
        self::assertCount(1, $check->getRepository(OAuthRefreshFamily::class)->findBy(['clientId' => self::OTHER_CLIENT_ID]));
        $renewed = $this->refresh($theirs['refresh_token'], self::OTHER_CLIENT_ID);
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('access_token', $renewed);
        self::assertNotNull($em->getRepository(User::class)->findOneBy(['email' => 'survivor@example.com']));
    }

    public function testApiRejectsDeletedUsersAccessToken(): void
    {
        $this->client->loginUser($this->user);
        $tokens = $this->authorizeAndExchange();

        static::getContainer()->get(AccountDeletionService::class)->delete(
            $this->freshUserByEmail('revocation-user@example.com')
        );

        $this->client->request('GET', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$tokens['access_token'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testApiRejectsUnverifiedUsersAccessToken(): void
    {
        $this->createUser('unverified@example.com', 'Fixture-Password-1', false);
        $this->registerPublicClient(self::OTHER_CLIENT_ID, self::OTHER_CLIENT_NAME);

        // Control: a verified User reaches the resource.
        $this->client->request('GET', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('revocation-user@example.com'),
        ]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/v1/expense-categories', [], [], [
            'HTTP_Authorization' => 'Bearer '.$this->craftToken('unverified@example.com'),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testWebLogoutKeepsCliRefreshWorking(): void
    {
        $this->client->loginUser($this->user);
        $tokens = $this->authorizeAndExchange();

        $crawler = $this->client->request('GET', '/dashboard');
        $logoutToken = $crawler->filter('form[action="/logout"] input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/logout', ['_csrf_token' => $logoutToken]);
        self::assertResponseRedirects();

        // The web session is over…
        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');

        // …but the CLI session survives: refresh works and the new access
        // token still opens the API.
        $renewed = $this->refresh($tokens['refresh_token']);
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('access_token', $renewed);

        $this->client->request('GET', '/api', [], [], [
            'HTTP_Authorization' => 'Bearer '.$renewed['access_token'],
        ]);
        self::assertResponseIsSuccessful();
    }

    private function changePassword(string $email, string $newPlainPassword): void
    {
        $em = $this->freshEm();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, $newPlainPassword));
        $em->flush();
        $em->clear();
    }

    private function setClientActive(string $identifier, bool $active): void
    {
        $manager = static::getContainer()->get(ClientManagerInterface::class);
        $client = $manager->find($identifier);
        self::assertNotNull($client);
        $client->setActive($active);
        $manager->save($client);
    }

    /**
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string}
     */
    private function authorizeAndExchange(string $clientId = self::CLIENT_ID): array
    {
        $code = $this->authorizeOnly($clientId);

        $params = [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => $this->verifier,
        ];
        if ('conf-cli' === $clientId) {
            $params['client_secret'] = 'conf-secret';
        }

        $this->client->request('POST', '/token', $params);
        self::assertResponseIsSuccessful();

        /** @var array{token_type: string, expires_in: int, access_token: string, refresh_token: string} */
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function authorizeOnly(string $clientId = self::CLIENT_ID): string
    {
        $url = $this->authorizeUrl($clientId);
        $crawler = $this->client->request('GET', $url);

        if (Response::HTTP_FOUND === $this->client->getResponse()->getStatusCode()) {
            return $this->codeFromRedirect();
        }

        $csrf = $crawler->filter('#oauth-consent-form input[name="_csrf_token"]')->attr('value');
        self::assertNotNull($csrf);
        $this->client->request('POST', $url, ['decision' => 'allow', '_csrf_token' => $csrf]);

        return $this->codeFromRedirect();
    }

    private function removeConsentRows(string $clientId): void
    {
        $em = $this->freshEm();
        foreach ($em->getRepository(OAuthConsent::class)->findBy(['clientId' => $clientId]) as $consent) {
            $em->remove($consent);
        }
        $em->flush();
    }

    private function exchangeCode(string $code, string $clientId = self::CLIENT_ID): void
    {
        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => $this->verifier,
        ]);
    }

    /**
     * @return array{token_type: string, expires_in: int, access_token: string, refresh_token: string}
     */
    private function refresh(string $refreshToken, string $clientId = self::CLIENT_ID, ?string $secret = null): array
    {
        $params = [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
        ];
        if (null !== $secret) {
            $params['client_secret'] = $secret;
        }

        $this->client->request('POST', '/token', $params);

        /** @var array{token_type: string, expires_in: int, access_token: string, refresh_token: string} */
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function authorizeUrl(string $clientId = self::CLIENT_ID): string
    {
        return '/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => OAuth2Config::SCOPE_FULL,
            'state' => 'revocation-state',
            'code_challenge' => $this->challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    private function codeFromRedirect(): string
    {
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertArrayHasKey('code', $query);
        self::assertIsString($query['code']);

        return $query['code'];
    }

    private function revokeGrant(string $clientId): void
    {
        $crawler = $this->client->request('GET', '/profile/connected-apps');
        self::assertResponseIsSuccessful();

        $csrf = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($csrf);

        $this->client->request('POST', '/profile/connected-apps/revoke', [
            'client_id' => $clientId,
            '_token' => $csrf,
        ]);
        self::assertResponseRedirects('/profile/connected-apps');
    }

    /**
     * Loads the User through the current container's entity manager: after the
     * test client reboots the kernel between requests, DatabaseTestCase::$em
     * belongs to the previous container and its entities are unknown to the
     * current one.
     */
    private function freshUserByEmail(string $email): User
    {
        $user = $this->freshEm()->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        return $user;
    }

    private function newestFamilyForUser(string $email): OAuthRefreshFamily
    {
        $em = $this->freshEm();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        // findBy (persister path), not a DQL entity parameter: DQL binds the
        // User in its 36-char string form, which matches nothing against the
        // binary(16) UUID column.
        $families = $em->getRepository(OAuthRefreshFamily::class)->findBy(['user' => $user], ['issuedAt' => 'DESC'], 1);
        self::assertNotEmpty($families);
        $family = $families[0];

        self::assertInstanceOf(OAuthRefreshFamily::class, $family);

        return $family;
    }

    /**
     * @return list<string>
     */
    private function familyIdsForUser(string $email): array
    {
        $em = $this->freshEm();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        $families = $em->getRepository(OAuthRefreshFamily::class)->findBy(['user' => $user]);

        return array_map(static fn (OAuthRefreshFamily $family): string => (string) $family->getId(), $families);
    }

    /**
     * @param list<string> $familyIds
     *
     * @return list<string>
     */
    private function tokenIdsForFamilies(array $familyIds): array
    {
        $em = $this->freshEm();
        $links = $em->getRepository(OAuthRefreshFamilyToken::class)->findAll();

        $ids = [];
        foreach ($links as $link) {
            $family = $link->getFamily();
            if (null !== $family && \in_array((string) $family->getId(), $familyIds, true)) {
                $ids[] = (string) $link->getTokenId();
            }
        }

        return array_values(array_unique($ids));
    }

    private function craftToken(string $userIdentifier): string
    {
        $entity = new ApiAccessTokenEntity(self::ISSUER, OAuth2Config::API_AUDIENCE);
        $entity->setIdentifier(bin2hex(random_bytes(16)));
        $clientEntity = new ClientEntity();
        $clientEntity->setIdentifier(self::CLIENT_ID);
        $clientEntity->setName(self::CLIENT_NAME);
        $entity->setClient($clientEntity);
        $entity->setUserIdentifier($userIdentifier);
        $entity->addScope(new RevocationTestScope(OAuth2Config::SCOPE_FULL));
        $entity->setExpiryDateTime(new DateTimeImmutable('+15 minutes'));
        $entity->setPrivateKey(new CryptKey($this->privateKeyPath()));

        return $entity->toString();
    }

    private function registerPublicClient(
        string $identifier = self::CLIENT_ID,
        string $name = self::CLIENT_NAME,
        array $scopes = [OAuth2Config::SCOPE_FULL],
    ): void {
        $manager = static::getContainer()->get(ClientManagerInterface::class);

        $client = new Client($name, $identifier, null);
        $client->setRedirectUris(new RedirectUri(self::REGISTERED_REDIRECT));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(...array_map(static fn (string $scope): Scope => new Scope($scope), $scopes));
        $manager->save($client);
    }

    private function registerConfidentialClient(): void
    {
        $manager = static::getContainer()->get(ClientManagerInterface::class);
        $hasher = static::getContainer()->get('league.oauth2_server.password_hasher');

        $client = new Client('Confidential CLI', 'conf-cli', $hasher->hash('conf-secret'));
        $client->setRedirectUris(new RedirectUri(self::REGISTERED_REDIRECT));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(new Scope(OAuth2Config::SCOPE_FULL));
        $manager->save($client);
    }

    private function privateKeyPath(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir').'/tests/Fixtures/oauth/private.pem';
    }
}

final class RevocationTestScope implements ScopeEntityInterface
{
    public function __construct(private readonly string $identifier)
    {
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function jsonSerialize(): string
    {
        return $this->identifier;
    }
}
