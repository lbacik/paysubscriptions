<?php

namespace App\Controller;

use App\Form\ContactType;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;

class ContactController extends AbstractController
{
    #[Route('/contact', name: 'app_contact')]
    public function index(
        Request $request,
        MailerInterface $mailer,
        string $contactEmail,
    ): Response {
        $form = $this->createForm(ContactType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $email = (new TemplatedEmail())
                ->from(new Address($form->get('email')->getData(), $form->get('name')->getData()))
                ->to($contactEmail)
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
