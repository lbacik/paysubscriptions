<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Subscription;
use App\Entity\User;
use App\Exception\CategoryOwnershipException;
use App\Form\SubscriptionType;
use App\Security\SubscriptionVoter;
use App\Service\ExpenseCategoryService;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
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
        private ExpenseCategoryService $categoryService,
    ) {
    }

    #[Route('/new', name: 'app_subscription_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        if ($request->isMethod('POST')) {
            // Creating the default category is a write-path side effect, so
            // it happens on submission only: rendering this form on GET must
            // never persist a row (prefetchers, crawlers, and repeated
            // navigation without submitting would otherwise silently create
            // categories). Ensuring before the form is built also keeps the
            // category choices in sync with what the submission may reference.
            $default = $this->categoryService->ensureDefaultCategory($user);

            // A form rendered while the owner had no categories has an empty
            // category select, so the first submission carries no category:
            // backfill it with the just-ensured default before binding.
            $submitted = $request->request->all();
            if (isset($submitted['subscription'])
                && \is_array($submitted['subscription'])
                && empty($submitted['subscription']['category'])
            ) {
                $submitted['subscription']['category'] = (string) $default->getId();
                $request->request->set('subscription', $submitted['subscription']);
            }
        }

        $subscription = new Subscription();
        // Read-only pre-select: never creates a row on GET.
        $subscription->setCategory($this->categoryService->findDefaultCategory($user));
        $mainCurrency = $this->getMainCurrency();
        $form = $this->createForm(
            SubscriptionType::class,
            $subscription,
            [
                'action' => $this->generateUrl('app_subscription_new'),
                'user' => $user,
                'main_currency' => $mainCurrency,
            ]
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $subscription->setOwner($this->getUser());
            try {
                $this->assertConvertedInput($form, $subscription, $mainCurrency);
            } catch (\InvalidArgumentException) {
                return $this->render('subscription/new.html.twig', [
                    'subscription' => $subscription,
                    'form' => $form,
                    'mainCurrency' => $mainCurrency,
                ]);
            }

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
            'mainCurrency' => $mainCurrency,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_subscription_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Subscription $subscription): Response
    {
        $this->denyAccessUnlessGranted(SubscriptionVoter::EDIT, $subscription);

        $user = $this->getUser();
        \assert($user instanceof User);

        $mainCurrency = $this->getMainCurrency();
        $originalCurrency = $subscription->getCurrency();
        $originalConverted = $subscription->getConvertedAmount();

        $form = $this->createForm(
            SubscriptionType::class,
            $subscription,
            [
                'action' => $this->generateUrl('app_subscription_edit', ['id' => $subscription->getId()]),
                'user' => $user,
                'main_currency' => $mainCurrency,
            ]
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $subscription->reconcileConverted($originalCurrency, $originalConverted, $mainCurrency);

            try {
                $this->assertConvertedInput($form, $subscription, $mainCurrency);
            } catch (\InvalidArgumentException) {
                return $this->render('subscription/edit.html.twig', [
                    'subscription' => $subscription,
                    'form' => $form,
                    'mainCurrency' => $mainCurrency,
                ]);
            }

            try {
                // Defense-in-depth: the form's category choice list is
                // already scoped to the user's own categories, so a cross-
                // user assignment normally fails form validation before
                // reaching assertCategoryOwnership(). This catch only
                // matters if that scoping is ever bypassed or loosened, so
                // it catches the ownership failure specifically and lets any
                // other logic error surface instead of masquerading as one.
                $this->subscriptionService->update($subscription);

                $this->addFlash('success', 'Subscription updated successfully');

                if ($request->isXmlHttpRequest() || $request->headers->get('Turbo-Frame')) {
                    return $this->streamResponse();
                }

                return $this->redirectToRoute('app_dashboard', [], Response::HTTP_SEE_OTHER);

            } catch (CategoryOwnershipException $exception) {
                $this->addFlash('danger', $exception->getMessage());

                return $this->redirectToRoute('app_dashboard', [], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('subscription/edit.html.twig', [
            'subscription' => $subscription,
            'form' => $form,
            'mainCurrency' => $mainCurrency,
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

    private function getMainCurrency(): ?string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getMainCurrency() : null;
    }

    private function assertConvertedInput(mixed $form, Subscription $subscription, ?string $mainCurrency): void
    {
        // Save-blocking validation only: a stale converted amount stays
        // saveable (aggregates exclude it) while banners and form warnings
        // guide the User to review it.
        $violations = $subscription->validateConverted($mainCurrency, true);

        foreach ($violations as $violation) {
            $form->addError(new FormError($violation));
        }

        if ($violations !== []) {
            throw new \InvalidArgumentException(implode(' ', $violations));
        }
    }

    private function streamResponse(): Response
    {
        // The table component re-reads the session-backed filter/sort state
        // from this same request, so the Turbo update keeps the list the User
        // was looking at.
        return $this->render('dashboard/_table_stream.html.twig', [], new Response('', 200, ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }
}
