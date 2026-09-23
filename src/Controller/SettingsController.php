<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\SettingsType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[isGranted('ROLE_USER')]
class SettingsController extends AbstractController
{
    #[Route('/settings', name: 'app_settings', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(SettingsType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Reminder settings live on the User only: future reminder
            // eligibility changes, Subscription dates never do.
            $entityManager->flush();

            $this->addFlash('success', 'Settings updated successfully');

            return $this->redirectToRoute('app_settings', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('settings/index.html.twig', [
            'form' => $form,
        ]);
    }
}
