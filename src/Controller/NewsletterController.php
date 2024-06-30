<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MailingSubscriptionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class NewsletterController extends AbstractController
{
    #[Route('/newsletter/subscribe', name: 'app_newsletter_subscribe', methods: ['POST'])]
    public function subscribe(
        Request $request,
        MailingSubscriptionService $mailingSubscriptionService,
        FormFactoryInterface $formFactory
    ): Response {
        $form = $formFactory->createNamed('', FormType::class, null, [
            'csrf_token_id' => 'newsletter',
        ])
            ->add('email', EmailType::class);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $mailingSubscriptionService->subscribe($form->get('email')->getData());

            $this->addFlash('success', 'Subscription queued. Wait for the confirmation email.');

            return $this->redirect($request->headers->get('referer'));
        }

        return new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
