<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Subscription;
use App\Form\SubscriptionType;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[isGranted('ROLE_USER')]
#[Route('/subscription')]
class SubscriptionController extends AbstractController
{
    public function __construct(
        private SubscriptionService $subscriptionService,
    ) {
    }

    #[Route('/new', name: 'app_subscription_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $subscription = new Subscription();
        $form = $this->createForm(
            SubscriptionType::class,
            $subscription,
            ['action' => $this->generateUrl('app_subscription_new')]
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $subscription->setOwner($this->getUser());
            try {
                $this->subscriptionService->add($subscription);

                $this->addFlash('success', 'Subscription created successfully');

                if ($request->headers->has('turbo-frame')) {
                    $subscriptions = $this->subscriptionService->get($this->getUser(), 'name', 'asc');
                    $newTable = $this->renderView('dashboard/_table.html.twig', [
                        'subscriptions' => $subscriptions,
                        'total' => [
                            'monthly' => 0.0,
                            'yearly' => 0.0,
                            'monthlyCalculated' => 0.0,
                            'yearlyCalculated' => 0.0,
                        ],
                        'addSubscriptionDisabled' => false,
                        'sort' => 'name',
                        'order' => 'asc',
                    ]);

                    $stream = $this->renderView('dashboard/turbo_stream.html.twig', [
                        'content' => $newTable,
                    ]);

                    $this->addFlash('stream', $stream);
                }

                return $this->redirectToRoute('app_dashboard', [], Response::HTTP_SEE_OTHER);

            } catch (\LogicException $exception) {
                $this->addFlash('danger', $exception->getMessage());

                return $this->redirectToRoute('app_dashboard', [], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('subscription/new.html.twig', [
            'subscription' => $subscription,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_subscription_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Subscription $subscription): Response
    {
        $form = $this->createForm(
            SubscriptionType::class,
            $subscription,
            ['action' => $this->generateUrl('app_subscription_edit', ['id' => $subscription->getId()])]
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->subscriptionService->update($subscription);

            $this->addFlash('success', 'Subscription updated successfully');

            return $this->redirectToRoute('app_dashboard', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('subscription/edit.html.twig', [
            'subscription' => $subscription,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_subscription_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        Subscription $subscription,
        EntityManagerInterface $entityManager
    ): Response {
        if ($this->isCsrfTokenValid('delete' . $subscription->getId(), $request->getPayload()->get('_token'))) {
            $entityManager->remove($subscription);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_dashboard', [], Response::HTTP_SEE_OTHER);
    }
}
