<?php

namespace App\Controller;

use App\Entity\User;
use App\RateLimiter\RateLimitGuard;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Security\AppCustomAuthenticator;
use App\Security\EmailVerifier;
use App\Service\ExpenseCategoryService;
use App\Service\TimezoneService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly EmailVerifier $emailVerifier,
        private readonly TimezoneService $timezoneService,
        private readonly string $systemEmail,
        private readonly ExpenseCategoryService $categoryService,
        private readonly LoggerInterface $logger,
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

            try {
                $entityManager->persist($user);
                $entityManager->flush();
            } catch (UniqueConstraintViolationException $exception) {
                // Lost a race with a concurrent registration for the same
                // address after UniqueEntity validation passed: re-render with
                // the same message instead of answering with an HTTP 500.
                $this->logger->warning('Duplicate registration attempt.', ['exception' => $exception]);
                $form->get('email')->addError(new FormError('There is already an account with this email'));

                return $this->render('registration/register.html.twig', [
                    'registrationForm' => $form,
                ], new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            // Every account starts with one editable `Subscriptions` category.
            $this->categoryService->ensureDefaultCategory($user);

            try {
                // generate a signed url and email it to the user
                $this->sendConfirmationEmail($user);
            } catch (\Throwable $exception) {
                // The account is usable; only the verification email failed.
                // Log for observability and tell the visitor how to recover
                // (the resend route) instead of answering with an HTTP 500.
                $this->logger->error('Verification email could not be sent.', ['exception' => $exception]);
                $this->addFlash(
                    'danger',
                    'Your account has been created, but we could not send the verification email. Please request a new one from the login page.'
                );

                return $this->redirectToRoute('app_login');
            }

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

    #[Route('/register/activation/resend', name: 'resend_activation', methods: ['POST'])]
    public function resendActivationEmail(
        Request $request,
        EntityManagerInterface $entityManager,
        CsrfTokenManagerInterface $csrfTokenManager,
        RateLimitGuard $rateLimitGuard,
        #[Autowire(service: 'limiter.activation_resend_email')]
        RateLimiterFactory $emailLimiter,
        #[Autowire(service: 'limiter.activation_resend_ip')]
        RateLimiterFactory $ipLimiter,
    ): Response {
        // A missing or forged token is rejected outright: silently succeeding
        // would turn the endpoint into a CSRF-driven email oracle.
        if (!$csrfTokenManager->isTokenValid(new CsrfToken(
            'resend_activation',
            (string) $request->request->get('_token', '')
        ))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $email = trim((string) $request->request->get('email', ''));

        // Throttled before any account lookup, so the answer reveals nothing
        // about whether the address is registered: both the per-address and
        // the per-IP window must accept the request.
        $retryAfter = $rateLimitGuard->retryAfterSeconds([
            [$emailLimiter, mb_strtolower($email)],
            [$ipLimiter, (string) $request->getClientIp()],
        ]);

        if (null !== $retryAfter) {
            throw new TooManyRequestsHttpException(
                $retryAfter,
                'Too many activation email requests. Please try again later.'
            );
        }

        // Verified and unknown addresses take the exact same path as
        // unverified ones — same flash, same redirect — except no email ever
        // leaves the server, so the endpoint cannot confirm registration.
        $user = '' !== $email
            ? $entityManager->getRepository(User::class)->findOneBy(['email' => $email])
            : null;

        if (null !== $user && !$user->isVerified()) {
            try {
                $this->sendConfirmationEmail($user);
            } catch (\Throwable $exception) {
                $this->logger->error('Activation email could not be resent.', ['exception' => $exception]);
            }
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
