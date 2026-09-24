<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Security\AppCustomAuthenticator;
use App\Security\EmailVerifier;
use App\Service\ExpenseCategoryService;
use App\Service\TimezoneService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly EmailVerifier $emailVerifier,
        private readonly TimezoneService $timezoneService,
        private readonly string $systemEmail,
        private readonly ExpenseCategoryService $categoryService,
    ) {
    }

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        Security $security,
        EntityManagerInterface $entityManager
    ): Response {
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // A missing or non-IANA browser zone is stored as UTC explicitly;
            // the User can correct it in settings.
            $user->setTimezone(
                $this->timezoneService->normalize($form->get('timezone')->getData())
            );

            // encode the plain password
            $user->setPassword(
                $userPasswordHasher->hashPassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );

            $entityManager->persist($user);
            $entityManager->flush();

            // Every account starts with one editable `Subscriptions` category.
            $this->categoryService->ensureDefaultCategory($user);

            // generate a signed url and email it to the user
            $this->sendConfirmationEmail($user);

            // do anything else you need here, like send an email
            $this->addFlash(
                'success',
                'Your account has been created. Please check your email for a verification link.'
            );

            // return $security->login($user, AppCustomAuthenticator::class, 'main');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(
        Request $request,
        TranslatorInterface $translator,
        UserRepository $userRepository
    ): Response {
        $email = $request->query->get('email');
        if (null === $email) {
            return $this->redirectToRoute('app_home');
        }

        $user = $userRepository->findOneBy(['email' => $email]);

        // Ensure the user exists in persistence
        if (null === $user) {
            return $this->redirectToRoute('app_home');
        }

        try {
            $this->emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('danger', $translator->trans($exception->getReason(), [], 'VerifyEmailBundle'));

            return $this->redirectToRoute('app_register');
        }

        // @TODO Change the redirect on success and handle or remove the flash message in your templates
        $this->addFlash('success', 'Your email address has been verified.');

        return $this->redirectToRoute('app_login');
    }

    #[Route('/register/activation/resend', name: 'resend_activation')]
    public function resendActivationEmail(
        Request $request,
        Security $security,
        EntityManagerInterface $entityManager
    ): Response {
        $email = $request->query->get('email');
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($user) {
            $this->sendConfirmationEmail($user);
        }

        $this->addFlash('success', 'Activation email has been sent to your email address.');
        // return $security->login($user, AppCustomAuthenticator::class, 'main');
        return $this->redirectToRoute('app_login');
    }

    private function sendConfirmationEmail(User $user): void
    {
        $this->emailVerifier->sendEmailConfirmation(
            'app_verify_email',
            $user,
            (new TemplatedEmail())
                ->from(new Address($this->systemEmail, 'PaySubscriptions'))
                ->to($user->getEmail())
                ->subject('Please Confirm your Email')
                ->htmlTemplate('registration/confirmation_email.html.twig')
        );
    }
}
