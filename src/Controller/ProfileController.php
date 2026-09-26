<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ProfileType;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {
    }

    #[Route('/profile', name: 'app_profile', methods: ['GET', 'POST'])]
    public function edit(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $previousMain = $user->getMainCurrency();
        $isFirstConfirmation = $previousMain === null;

        $form = $this->createForm(ProfileType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $currentMain = $user->getMainCurrency();

            // Stored converted amounts keep the currency they were entered
            // in: never reinterpreted, only flagged for review. This applies
            // both to a main-currency change and to a first confirmation
            // (legacy Subscriptions may already carry another currency).
            $pending = $this->subscriptionService->getPendingReviewSubscriptions(
                $user->getSubscriptions()->toArray(),
                $currentMain
            );

            if ($pending === []) {
                $this->addFlash('success', $isFirstConfirmation || $previousMain !== $currentMain
                    ? sprintf('Your main currency is now %s. Totals are reported in %s.', $currentMain, $currentMain)
                    : 'Your profile has been updated.');
            } else {
                $this->addFlash('danger', sprintf(
                    'Your main currency is now %s. %d subscription(s) need a converted amount reviewed: please review them below. Totals exclude them until reviewed.',
                    $currentMain,
                    \count($pending)
                ));
            }

            return $this->redirectToRoute('app_dashboard', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('profile/edit.html.twig', [
            'form' => $form,
            'isFirstConfirmation' => $isFirstConfirmation,
        ]);
    }
}
