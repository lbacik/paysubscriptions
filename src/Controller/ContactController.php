<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\ContactType;
use App\Service\RecaptchaVerifierInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;

class ContactController extends AbstractController
{
    public function __construct(
        private readonly RecaptchaVerifierInterface $recaptcha,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/contact', name: 'app_contact')]
    public function index(
        Request $request,
        MailerInterface $mailer,
        string $systemEmail,
        string $contactEmail,
    ): Response {
        $form = $this->createForm(ContactType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->recaptcha->verify(
                (string) $request->request->get('g-recaptcha-response', ''),
                $request->getClientIp()
            )) {
                $this->addFlash('danger', 'Invalid reCAPTCHA response.');

                return $this->redirectToRoute('app_contact');
            }

            $email = (new TemplatedEmail())
                ->from(new Address($systemEmail, 'PaySubscriptions'))
                ->to($contactEmail)
                // The From address has no mailbox; replies must reach the visitor's validated address.
                ->replyTo((string) $form->get('email')->getData())
                ->subject('PaySubscriptions Contact: ' . $form->get('subject')->getData())
                ->htmlTemplate('contact/contact_email.html.twig')
                ->context([
                    'name' => $form->get('name')->getData(),
                    'senderEmail' => $form->get('email')->getData(),
                    'message' => $form->get('message')->getData(),
                ]);

            try {
                $mailer->send($email);
            } catch (\Throwable $exception) {
                $this->logger->error('Contact message could not be sent.', ['exception' => $exception]);
                $this->addFlash('danger', 'An error occurred while sending your message.');

                return $this->redirectToRoute('app_contact');
            }

            $this->addFlash('success', 'Your message has been sent.');

            return $this->redirectToRoute('app_contact');
        }

        return $this->render('contact/index.html.twig', [
            'form' => $form,
        ]);
    }
}
