<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MailingSubscriptionService;
use App\Service\RecaptchaVerifierInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

class NewsletterController extends AbstractController
{
    public function __construct(
        private readonly RecaptchaVerifierInterface $recaptcha,
    ) {
    }

    #[Route('/newsletter/subscribe', name: 'app_newsletter_subscribe', methods: ['POST'])]
    public function subscribe(
        Request $request,
        MailingSubscriptionService $mailingSubscriptionService,
        FormFactoryInterface $formFactory,
        #[Autowire(service: 'limiter.newsletter_ip')]
        RateLimiterFactory $ipLimiter,
    ): Response {
        // Throttled before any other work (issue #143): a script iterating
        // over victim addresses must not turn the list provider into a
        // spam cannon, no matter how cheap each request is.
        $limit = $ipLimiter->create((string) $request->getClientIp())->consume();

        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(
                max(1, $limit->getRetryAfter()->getTimestamp() - time()),
                'Too many subscription attempts. Please try again later.'
            );
        }

        $form = $formFactory->createNamed('', FormType::class, null, [
            'csrf_token_id' => 'newsletter',
            // The footer posts a flat structure where the reCAPTCHA token
            // rides alongside the mapped fields; it is verified separately
            // below (the contact-form pattern), not by the form itself.
            'allow_extra_fields' => true,
        ])
            ->add('email', EmailType::class);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Same invisible reCAPTCHA as the contact form: enforced whenever
            // keys are configured, which fails closed like the contact flow.
            if (!$this->recaptcha->verify(
                (string) $request->request->get('g-recaptcha-response', ''),
                $request->getClientIp()
            )) {
                $this->addFlash('danger', 'Invalid reCAPTCHA response.');

                return $this->redirectToRoute('app_home');
            }

            $mailingSubscriptionService->subscribe($form->get('email')->getData());

            $this->addFlash('success', 'Subscription queued. Wait for the confirmation email.');

            // A fixed internal route, never the Referer header: the header is
            // missing (and crashed with a 500) for direct POSTs and is an
            // open redirect in an attacker's hands.
            return $this->redirectToRoute('app_home');
        }

        return new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
