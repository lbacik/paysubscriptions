<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AccountDeletionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class AccountController extends AbstractController
{
    public function __construct(
        private readonly AccountDeletionService $accountDeletionService,
        private readonly Security $security,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('/account/delete', name: 'app_account_delete', methods: ['GET'])]
    public function show(): Response
    {
        return $this->render('account/delete.html.twig');
    }

    #[Route('/account/delete', name: 'app_account_delete_confirm', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $token = $this->requestInput($request, '_token');
        if (!\is_string($token) || !$this->isCsrfTokenValid('delete_account', $token)) {
            $this->addFlash('danger', 'The confirmation was invalid. Please try again.');

            return $this->render('account/delete.html.twig', [], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        if (!$this->requestInput($request, 'confirm')) {
            $this->addFlash('danger', 'Please tick the confirmation checkbox to delete your account.');

            return $this->render('account/delete.html.twig', [], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $user = $this->getUser();
        if (null === $user || !($user instanceof \App\Entity\User)) {
            throw $this->createAccessDeniedException();
        }

        // Deletion is irreversible, and a remember-me session can stay alive
        // for days on a shared or briefly unattended browser. The current
        // password proves the requester is really the account owner.
        $password = $this->requestInput($request, 'currentPassword');
        if (!\is_string($password) || '' === $password || !$this->passwordHasher->isPasswordValid($user, $password)) {
            $this->addFlash('danger', 'The password you entered is not correct. Your account was not deleted.');

            return $this->render('account/delete.html.twig', [], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $this->accountDeletionService->delete($user);

        // Drop the authentication (including the remember-me cookie) and throw
        // away the session so no active session survives the deleted account.
        $this->security->logout(false);
        $request->getSession()->invalidate();

        $this->addFlash('success', 'Your account has been permanently deleted.');

        return $this->redirectToRoute('app_home');
    }

    /**
     * Plain HTML forms land in the request bag; Turbo/JSON payloads land in
     * the payload bag. Accept both so the confirmation works either way.
     */
    private function requestInput(Request $request, string $key): mixed
    {
        return $request->request->get($key) ?? $request->getPayload()->get($key);
    }
}
