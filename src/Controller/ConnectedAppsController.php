<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\OAuth2\ConsentRevoker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * User-facing list of approved OAuth2 clients (issue #92).
 *
 * A User sees every remembered grant and disconnects each client
 * individually. Disconnecting deletes the remembered consent — so the next
 * authorization asks for consent again — and revokes the client's usable
 * refresh-token families and pending authorization codes immediately.
 * Already-issued access tokens stay usable until their 15-minute expiry; the
 * page states this delay.
 */
#[IsGranted('ROLE_USER')]
class ConnectedAppsController extends AbstractController
{
    public function __construct(
        private readonly ConsentRevoker $revoker,
    ) {
    }

    #[Route('/profile/connected-apps', name: 'app_connected_apps', methods: ['GET'])]
    public function list(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('profile/connected_apps.html.twig', [
            'grants' => $this->revoker->listFor($user),
        ]);
    }

    #[Route('/profile/connected-apps/revoke', name: 'app_connected_apps_revoke', methods: ['POST'])]
    public function revoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $clientId = $request->request->get('client_id') ?? $request->getPayload()->get('client_id');
        $token = $request->request->get('_token') ?? $request->getPayload()->get('_token');
        if (!\is_string($clientId) || '' === $clientId
            || !\is_string($token) || !$this->isCsrfTokenValid('connected_apps', $token)
        ) {
            $this->addFlash('danger', 'The confirmation was invalid. Please try again.');

            return $this->redirectToRoute('app_connected_apps', [], Response::HTTP_SEE_OTHER);
        }

        if ($this->revoker->revoke($user, $clientId)) {
            $this->addFlash('success', 'The application was disconnected. It will ask for your consent again before accessing your data.');
        }

        return $this->redirectToRoute('app_connected_apps', [], Response::HTTP_SEE_OTHER);
    }
}
