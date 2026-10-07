<?php

declare(strict_types=1);

namespace App\OAuth2;

use App\Connection\ClientApprovalPolicy;
use App\Connection\ConnectionDecision;
use App\Entity\OAuthConsent;
use App\Entity\User;
use App\Repository\OAuthConsentRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Resolves authorization-code requests with S256 PKCE (issue #88).
 *
 * Anonymous Users never reach this listener: `access_control` on /authorize
 * routes them through the existing web login first, preserving the full
 * authorize URL as the post-login target. The listener then:
 *
 * - rejects requests whose raw `scope` parameter is not exactly api:full
 *   (empty, unknown, and unapproved scope requests fail; the bundle would
 *   otherwise silently default an omitted scope),
 * - rejects requests whose Client may not act for the User (unverified User,
 *   unknown or inactive Client, Client not approved for api:full) through the
 *   shared ClientApprovalPolicy,
 * - requires S256 PKCE for every client (RFC 9700),
 * - auto-approves when the User already consented to this client identity and
 *   scope set, otherwise renders the consent screen and honors an explicit
 *   allow/deny decision posted back to /authorize.
 */
final class OAuthAuthorizationListener
{
    private const DECISION_ALLOW = 'allow';
    private const DECISION_DENY = 'deny';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly OAuthConsentRepository $consents,
        private readonly EntityManagerInterface $em,
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly ClientApprovalPolicy $approvals,
    ) {
    }

    #[AsEventListener(event: OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE)]
    public function onAuthorizationRequest(AuthorizationRequestResolveEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            $event->resolveAuthorization(false);

            return;
        }

        $this->assertExplicitFullScope($request, $event->getRedirectUri());

        $user = $event->getUser();
        if (!$user instanceof User) {
            $event->resolveAuthorization(false);

            return;
        }

        $client = $event->getClient();

        $this->assertConnectionApproved($user, $client, $event->getRedirectUri());
        $this->assertS256Pkce($event);

        $scopes = [OAuth2Config::SCOPE_FULL];

        $consent = $this->consents->findForClient($user, $client->getIdentifier());
        if (null !== $consent && $consent->covers($client->getName(), $scopes)) {
            $event->resolveAuthorization(true);

            return;
        }

        if ($request->isMethod('POST')) {
            $decision = $request->request->get('decision');
            if (\is_string($decision) && $this->isCsrfValid($request)) {
                if (self::DECISION_ALLOW === $decision) {
                    $this->rememberConsent($user, $consent, $client->getIdentifier(), $client->getName(), $scopes);
                    $event->resolveAuthorization(true);

                    return;
                }

                if (self::DECISION_DENY === $decision) {
                    $event->resolveAuthorization(false);

                    return;
                }
            }
        }

        $event->setResponse(new Response($this->renderConsent($request, $user, $client->getName(), $client->getIdentifier())));
    }

    /**
     * The raw request must carry exactly `scope=api:full`. League validates
     * known scopes and the bundle defaults an omitted parameter, so this
     * explicit check is what rejects empty requests.
     */
    private function assertExplicitFullScope(Request $request, ?string $redirectUri): void
    {
        $rawScope = $request->query->get('scope');

        if (OAuth2Config::SCOPE_FULL !== $rawScope) {
            throw OAuthServerException::invalidScope(
                \is_string($rawScope) && '' !== $rawScope ? $rawScope : '(empty)',
                $redirectUri,
            );
        }
    }

    /**
     * The Client may act for this User only as the shared ClientApprovalPolicy
     * decides: the User must exist and be verified, and the Client must exist,
     * be active, and be approved for api:full. Any refusal keeps the
     * checkpoint's protocol error (invalid_scope with the redirect).
     *
     * @param object{isActive(): bool, getScopes(): iterable<mixed>} $client
     */
    private function assertConnectionApproved(User $user, object $client, ?string $redirectUri): void
    {
        if (ConnectionDecision::Allowed !== $this->approvals->decide($user, $client)) {
            throw OAuthServerException::invalidScope(OAuth2Config::SCOPE_FULL, $redirectUri);
        }
    }

    /**
     * S256 PKCE is required for every client, public and confidential.
     */
    private function assertS256Pkce(AuthorizationRequestResolveEvent $event): void
    {
        if (null === $event->getCodeChallenge() || 'S256' !== $event->getCodeChallengeMethod()) {
            throw OAuthServerException::invalidRequest(
                'code_challenge',
                'A `code_challenge` with method `S256` is required.',
            );
        }
    }

    private function isCsrfValid(Request $request): bool
    {
        $token = $request->request->get('_csrf_token');

        return \is_string($token) && $this->csrf->isTokenValid(new CsrfToken('oauth_consent', $token));
    }

    /**
     * @param list<string> $scopes
     */
    private function rememberConsent(
        User $user,
        ?OAuthConsent $existing,
        string $clientId,
        string $clientName,
        array $scopes,
    ): void {
        $consent = $existing ?? (new OAuthConsent())->setUser($user)->setClientId($clientId);
        $consent->setClientName($clientName)->setScopes($scopes);

        $this->em->persist($consent);
        $this->em->flush();
    }

    private function renderConsent(Request $request, User $user, string $clientName, string $clientId): string
    {
        // Other applications this User already approved, so the screen shows
        // the full grant picture next to the new request (issue #92).
        $grants = array_values(array_filter(
            $this->consents->findBy(['user' => $user], ['updatedAt' => 'DESC']),
            static fn (OAuthConsent $grant): bool => $grant->getClientId() !== $clientId,
        ));

        return $this->twig->render('oauth/consent.html.twig', [
            'client_name' => $clientName,
            'client_id' => $clientId,
            'scope' => OAuth2Config::SCOPE_FULL,
            'authorize_url' => $request->getUri(),
            'csrf_token' => $this->csrf->getToken('oauth_consent')->getValue(),
            'grants' => $grants,
        ]);
    }
}
