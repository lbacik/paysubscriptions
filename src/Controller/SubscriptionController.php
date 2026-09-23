<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Subscription;
use App\Form\SubscriptionType;
use App\Security\SubscriptionVoter;
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

                if ($request->isXmlHttpRequest() || $request->headers->get('Turbo-Frame')) {
                    return $this->streamResponse();
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
        $this->denyAccessUnlessGranted(SubscriptionVoter::EDIT, $subscription);

        $form = $this->createForm(
            SubscriptionType::class,
            $subscription,
            ['action' => $this->generateUrl('app_subscription_edit', ['id' => $subscription->getId()])]
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->subscriptionService->update($subscription);

            $this->addFlash('success', 'Subscription updated successfully');

            if ($request->isXmlHttpRequest() || $request->headers->get('Turbo-Frame')) {
                return $this->streamResponse();
            }

            return $this->redirectToRoute('app_dashboard', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('subscription/edit.html.twig', [
            'subscription' => $subscription,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_subscription_delete', methods: ['GET', 'POST'])]
    public function delete(
        Request $request,
        Subscription $subscription,
        EntityManagerInterface $entityManager
    ): Response {
        $this->denyAccessUnlessGranted(SubscriptionVoter::DELETE, $subscription);

        if ($this->isCsrfTokenValid('delete' . $subscription->getId(), $request->getPayload()->get('_token'))) {
            $entityManager->remove($subscription);
            $entityManager->flush();

            $this->addFlash('success', 'Subscription deleted successfully');

            if ($request->isXmlHttpRequest() || $request->headers->get('Turbo-Frame')) {
                return $this->streamResponse();
            }

            return $this->redirectToRoute('app_dashboard', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('subscription/delete.html.twig', [
            'subscription' => $subscription,
        ]);
    }

    private function streamResponse(): Response
    {
        $subscriptions = $this->subscriptionService->get($this->getUser(), 'name', 'asc');

        return $this->render('dashboard/_table_stream.html.twig', [
            'subscriptions' => $subscriptions,
            'order' => 'asc',
            'sort' => 'name',
        ], new Response('', 200, ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }
}
