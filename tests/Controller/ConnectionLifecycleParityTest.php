<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\OAuthConsent;
use App\Entity\User;
use App\OAuth2\OAuth2Config;
use App\Tests\DatabaseTestCase;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Parity over the seven revocation triggers (ADR 0005, issue #135).
 *
 * Every trigger is driven through its real entry point — the Connected apps
 * form, `POST /revoke`, the password-change (reset) form, deactivating a
 * Client and flushing, removing a Client, the account-deletion form, and the
 * emergency console command — and asserts the same five lifecycle facts:
 *
 * - whether the Connection (consent) still exists;
 * - the targeted Client sessions can no longer refresh;
 * - untouched Client sessions (other User, other Client, and for `/revoke`
 *   the other session of the same pair) still refresh;
 * - a pending authorization code covered by the trigger can no longer be
 *   exchanged;
 * - re-authorizing shows the consent screen exactly when the Connection
 *   ended, and auto-approves otherwise.
 *
 * The expected values are the ADR 0005 table, written literally in the
 * provider: only disconnect, Client removal, and account deletion end the
 * Connection; `/revoke` revokes one Client session and keeps it; password
 * change, Client deactivation, and the emergency command revoke credentials
 * and keep it (the emergency command additionally revokes pending codes).
 */
final class ConnectionLifecycleParityTest extends DatabaseTestCase
{
    private const TARGET_CLIENT_ID = 'parity-cli';
    private const TARGET_CLIENT_NAME = 'Parity CLI';
    private const OTHER_CLIENT_ID = 'parity-other';
    private const OTHER_CLIENT_NAME = 'Parity Other';
    private const REGISTERED_REDIRECT = 'http://127.0.0.1/callback';
    private const REDIRECT_URI = 'http://127.0.0.1:54123/callback';
    private const TARGET_EMAIL = 'parity-target@example.com';
    private const OTHER_EMAIL = 'parity-other@example.com';
    private const PASSWORD = 'Fixture-Password-1';
    private const NEW_PASSWORD = 'Parity-BatteryStaple99!';

    private User $target;
    private User $other;
    private string $verifier;
    private string $challenge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->target = $this->createUser(self::TARGET_EMAIL, self::PASSWORD, true);
        $this->other = $this->createUser(self::OTHER_EMAIL, self::PASSWORD, true);
        $this->registerPublicClient(self::TARGET_CLIENT_ID, self::TARGET_CLIENT_NAME);
        $this->registerPublicClient(self::OTHER_CLIENT_ID, self::OTHER_CLIENT_NAME);

        $this->verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $this->challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
    }

    /**
     * @dataProvider triggers
     */
    public function testTriggerParity(
        string $trigger,
        bool $consentExists,
        bool $samePairOtherRefreshes,
        bool $otherUserRefreshes,
        bool $otherClientRefreshes,
        bool $coveredCodeFails,
        string $reauth,
        int $targetStatus,
        string $targetMarker,
    ): void {
        // Two Client sessions of the target (User, Client) pair, one session
        // of the same User under the other Client, one session of the other
        // User under the target Client, plus a pending authorization code for
        // the target pair (covered by every trigger) and one for the
        // untouched pair (covered only by the emergency command).
        $this->client->loginUser($this->target);
        $sessionA = $this->authorizeAndExchange(self::TARGET_CLIENT_ID);
        $sessionB = $this->authorizeAndExchange(self::TARGET_CLIENT_ID);
        $sessionOtherClient = $this->authorizeAndExchange(self::OTHER_CLIENT_ID);
        $coveredCode = $this->authorizeOnly(self::TARGET_CLIENT_ID);

        $this->client->loginUser($this->other);
        $sessionOtherUser = $this->authorizeAndExchange(self::TARGET_CLIENT_ID);
        $untouchedCode = $this->authorizeOnly(self::OTHER_CLIENT_ID);

        $this->fireTrigger($trigger, $sessionA['refresh_token']);

        // The Connection (consent) still exists exactly when the trigger
        // revokes credentials instead of ending it.
        self::assertSame($consentExists, $this->targetConsentExists(), "consent for trigger '{$trigger}'");

        // The targeted sessions can no longer refresh.
        $this->refresh($sessionA['refresh_token'], self::TARGET_CLIENT_ID);
        self::assertResponseStatusCodeSame($targetStatus, "targeted refresh for trigger '{$trigger}'");
        self::assertStringContainsString($targetMarker, (string) $this->client->getResponse()->getContent());

        // Untouched sessions keep working exactly where ADR 0005 leaves them.
        $this->assertRefreshOutcome($sessionB['refresh_token'], self::TARGET_CLIENT_ID, $samePairOtherRefreshes, "same-pair session for trigger '{$trigger}'");
        $this->assertRefreshOutcome($sessionOtherUser['refresh_token'], self::TARGET_CLIENT_ID, $otherUserRefreshes, "other-User session for trigger '{$trigger}'");
        $this->assertRefreshOutcome($sessionOtherClient['refresh_token'], self::OTHER_CLIENT_ID, $otherClientRefreshes, "other-Client session for trigger '{$trigger}'");

        // A pending authorization code covered by the trigger can no longer
        // be exchanged (`/revoke` covers a single session, so its pending
        // code still redeems).
        $this->exchangeCode($coveredCode, self::TARGET_CLIENT_ID);
        if ($coveredCodeFails) {
            self::assertFalse($this->client->getResponse()->isSuccessful(), "covered code for trigger '{$trigger}'");
            // A removed Client fails client authentication (invalid_client);
            // every other trigger fails the grant itself (invalid_grant).
            $codeMarker = 'remove' === $trigger ? 'invalid_client' : 'invalid_grant';
            self::assertStringContainsString($codeMarker, (string) $this->client->getResponse()->getContent());
        } else {
            self::assertResponseIsSuccessful("covered code for trigger '{$trigger}'");
        }

        // A code outside every trigger's scope survives everything but the
        // emergency command.
        $this->exchangeCode($untouchedCode, self::OTHER_CLIENT_ID);
        if ('emergency' === $trigger) {
            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, 'untouched code after emergency');
            self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());
        } else {
            self::assertResponseIsSuccessful('untouched code for trigger '.$trigger);
        }

        $this->assertReauth($trigger, $reauth);
    }

    /**
     * @return array<string, array{string, bool, bool, bool, bool, bool, string, int, string}>
     */
    public static function triggers(): array
    {
        return [
            // trigger, consent?, same-pair?, other User?, other Client?,
            // covered code fails?, re-auth, targeted status, targeted marker
            'disconnect' => ['disconnect', false, false, true, true, true, 'screen', Response::HTTP_BAD_REQUEST, 'invalid_grant'],
            'revoke' => ['revoke', true, true, true, true, false, 'auto', Response::HTTP_BAD_REQUEST, 'invalid_grant'],
            'password' => ['password', true, false, true, false, true, 'auto', Response::HTTP_BAD_REQUEST, 'invalid_grant'],
            'deactivate' => ['deactivate', true, false, false, true, true, 'auto', Response::HTTP_BAD_REQUEST, 'invalid_grant'],
            'remove' => ['remove', false, false, false, true, true, 'screen', Response::HTTP_UNAUTHORIZED, 'invalid_client'],
            'delete' => ['delete', false, false, true, false, true, 'impossible', Response::HTTP_BAD_REQUEST, 'invalid_grant'],
            'emergency' => ['emergency', true, false, false, false, true, 'auto', Response::HTTP_BAD_REQUEST, 'invalid_grant'],
        ];
    }

    public function testRevokeKeepsConsentAndAutoApproves(): void
    {
        $this->client->loginUser($this->target);
        $tokens = $this->authorizeAndExchange(self::TARGET_CLIENT_ID);

        $this->client->request('POST', '/revoke', [
            'token' => $tokens['refresh_token'],
            'client_id' => self::TARGET_CLIENT_ID,
        ]);
        self::assertResponseIsSuccessful();

        self::assertTrue($this->targetConsentExists());
        $this->client->request('GET', $this->authorizeUrl(self::TARGET_CLIENT_ID));
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
    }

    public function testRemovedClientReregisteredUnderSameIdentifierShowsConsentScreen(): void
    {
        $this->client->loginUser($this->target);
        $this->authorizeAndExchange(self::TARGET_CLIENT_ID);

        $this->client->request('GET', '/login');
        $manager = static::getContainer()->get(ClientManagerInterface::class);
        $manager->remove($manager->find(self::TARGET_CLIENT_ID));
        self::assertFalse($this->targetConsentExists());

        $this->registerPublicClient(self::TARGET_CLIENT_ID, self::TARGET_CLIENT_NAME);

        $this->client->loginUser($this->target);
        $this->client->request('GET', $this->authorizeUrl(self::TARGET_CLIENT_ID));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::TARGET_CLIENT_NAME, (string) $this->client->getResponse()->getContent());
    }

    public function testEmergencyCommandFailsPendingAuthorizationCodeWithInvalidGrant(): void
    {
        $this->client->loginUser($this->target);
        $code = $this->authorizeOnly(self::TARGET_CLIENT_ID);

        $this->runEmergencyCommand();

        $this->exchangeCode($code, self::TARGET_CLIENT_ID);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('invalid_grant', (string) $this->client->getResponse()->getContent());
    }

    private function fireTrigger(string $trigger, string $targetRefreshToken): void
    {
        switch ($trigger) {
            case 'disconnect':
                $this->client->loginUser($this->target);
                $this->revokeGrant(self::TARGET_CLIENT_ID);
                break;
            case 'revoke':
                // One session of the pair is revoked through the real RFC
                // 7009 endpoint; the pair's other session must keep working.
                $this->client->request('POST', '/revoke', [
                    'token' => $targetRefreshToken,
                    'client_id' => self::TARGET_CLIENT_ID,
                ]);
                self::assertResponseIsSuccessful();
                break;
            case 'password':
                $this->client->loginUser($this->target);
                $this->changePasswordThroughForm();
                break;
            case 'deactivate':
                $this->setClientActive(self::TARGET_CLIENT_ID, false);
                break;
            case 'remove':
                $this->client->request('GET', '/login');
                $manager = static::getContainer()->get(ClientManagerInterface::class);
                $manager->remove($manager->find(self::TARGET_CLIENT_ID));
                break;
            case 'delete':
                $this->deleteAccountThroughForm();
                break;
            case 'emergency':
                $this->runEmergencyCommand();
                break;
            default:
                self::fail("unknown trigger '{$trigger}'");
        }
    }

    private function assertReauth(string $trigger, string $reauth): void
    {
        if ('impossible' === $reauth) {
            self::assertNull(
                $this->freshEm()->getRepository(User::class)->findOneBy(['email' => self::TARGET_EMAIL]),
                "target User gone after trigger '{$trigger}'"
            );

            return;
        }

        if ('remove' === $trigger) {
            // A Client re-registered under the removed identifier starts with
            // no Connections: the consent screen shows again.
            $this->registerPublicClient(self::TARGET_CLIENT_ID, self::TARGET_CLIENT_NAME);
        }

        if ('deactivate' === $trigger) {
            // The Connection survived deactivation: re-enabling needs fresh
            // authorization but no re-consent.
            $this->setClientActive(self::TARGET_CLIENT_ID, true);
        }

        $this->client->loginUser($this->freshUserByEmail(self::TARGET_EMAIL));
        $this->client->request('GET', $this->authorizeUrl(self::TARGET_CLIENT_ID));

        if ('screen' === $reauth) {
            self::assertResponseIsSuccessful("consent screen after trigger '{$trigger}'");
            self::assertStringContainsString(self::TARGET_CLIENT_NAME, (string) $this->client->getResponse()->getContent());
        } else {
            self::assertResponseStatusCodeSame(Response::HTTP_FOUND, "auto-approve after trigger '{$trigger}'");
            $this->codeFromRedirect();
        }
    }

    private function assertRefreshOutcome(string $refreshToken, string $clientId, bool $expectedSuccess, string $message): void
    {
        $this->refresh($refreshToken, $clientId);

        if ($expectedSuccess) {
            self::assertResponseIsSuccessful($message);
        } else {
            self::assertFalse($this->client->getResponse()->isSuccessful(), $message);
        }
    }

    private function targetConsentExists(): bool
    {
        $em = $this->freshEm();
        foreach ($em->getRepository(OAuthConsent::class)->findBy(['clientId' => self::TARGET_CLIENT_ID]) as $consent) {
            if (self::TARGET_EMAIL === $consent->getUser()?->getEmail()) {
                return true;
            }
        }

        return false;
    }

    private function changePasswordThroughForm(): void
    {
        /** @var ResetPasswordHelperInterface $helper */
        $helper = static::getContainer()->get(ResetPasswordHelperInterface::class);
        $token = $helper->generateResetToken($this->freshUserByEmail(self::TARGET_EMAIL))->getToken();

        $this->client->request('GET', '/reset-password/reset/'.$token);
        $this->client->followRedirect();
        $form = $this->client->getCrawler()->filter('form[name="change_password_form"]')->form([
            'change_password_form[plainPassword][first]' => self::NEW_PASSWORD,
            'change_password_form[plainPassword][second]' => self::NEW_PASSWORD,
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/login');
    }

    private function deleteAccountThroughForm(): void
    {
        $this->client->loginUser($this->freshUserByEmail(self::TARGET_EMAIL));
        $crawler = $this->client->request('GET', '/account/delete');
        $form = $crawler->selectButton('Delete my account permanently')->form();
        $form['confirm']->tick();
        $form['currentPassword'] = self::PASSWORD;
        $this->client->submit($form);
        self::assertResponseRedirects('/');
    }

    private function runEmergencyCommand(): void
    {
        $application = new Application($this->client->getKernel());
        $command = $application->find('app:oauth:revoke-refresh-families');
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
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
    private function authorizeAndExchange(string $clientId): array
    {
        $code = $this->authorizeOnly($clientId);

        $this->client->request('POST', '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => $this->verifier,
        ]);
        self::assertResponseIsSuccessful();

        /** @var array{token_type: string, expires_in: int, access_token: string, refresh_token: string} */
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function authorizeOnly(string $clientId): string
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

    private function exchangeCode(string $code, string $clientId): void
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
    private function refresh(string $refreshToken, string $clientId): array
    {
        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
        ]);

        /** @var array{token_type: string, expires_in: int, access_token: string, refresh_token: string} */
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function authorizeUrl(string $clientId): string
    {
        return '/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => OAuth2Config::SCOPE_FULL,
            'state' => 'parity-state',
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

    private function freshUserByEmail(string $email): User
    {
        $user = $this->freshEm()->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        return $user;
    }

    private function registerPublicClient(string $identifier, string $name): void
    {
        $manager = static::getContainer()->get(ClientManagerInterface::class);

        $client = new Client($name, $identifier, null);
        $client->setRedirectUris(new RedirectUri(self::REGISTERED_REDIRECT));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(new Scope(OAuth2Config::SCOPE_FULL));
        $manager->save($client);
    }
}
