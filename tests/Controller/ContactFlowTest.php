<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Double\FakeRecaptchaRequestMethod;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Behavioral coverage for the contact journey (issue #35).
 *
 * Valid and invalid submissions must never produce an HTTP 500, a valid
 * submission must queue a message to the configured contact recipient, and
 * failure paths (failed verification, mailer outage) must stay observable
 * without leaking internals to the visitor.
 *
 * reCAPTCHA runs through the test-only fake transport
 * (config/packages/test/recaptcha.yaml): the token `valid-test-token`
 * verifies, any other token fails. A dummy secret is set because the real
 * verifier fail-closes when no secret is configured.
 */
final class ContactFlowTest extends WebTestCase
{
    use MailerAssertionsTrait;
    use ResetDatabase;

    private KernelBrowser $client;

    /**
     * @var array<string,array{putenv: string|false, server: string|null, env: string|null}>
     */
    private array $originalRecaptchaEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['GOOGLE_RECAPTCHA_SITE_KEY', 'GOOGLE_RECAPTCHA_SECRET'] as $key) {
            $this->originalRecaptchaEnv[$key] = [
                'putenv' => getenv($key),
                'server' => $_SERVER[$key] ?? null,
                'env' => $_ENV[$key] ?? null,
            ];
        }

        putenv('GOOGLE_RECAPTCHA_SITE_KEY=test-site-key');
        putenv('GOOGLE_RECAPTCHA_SECRET=test-secret');
        $_SERVER['GOOGLE_RECAPTCHA_SITE_KEY'] = 'test-site-key';
        $_SERVER['GOOGLE_RECAPTCHA_SECRET'] = 'test-secret';
        $_ENV['GOOGLE_RECAPTCHA_SITE_KEY'] = 'test-site-key';
        $_ENV['GOOGLE_RECAPTCHA_SECRET'] = 'test-secret';

        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        foreach ($this->originalRecaptchaEnv as $key => $original) {
            if (false === $original['putenv']) {
                putenv($key);
            } else {
                putenv($key.'='.$original['putenv']);
            }

            if (null === $original['server']) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $original['server'];
            }

            if (null === $original['env']) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $original['env'];
            }
        }

        parent::tearDown();
    }

    public function testContactPageRendersWithoutRealRecaptchaSecrets(): void
    {
        $this->client->request('GET', '/contact');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="contact"]');
    }

    public function testInvalidSubmissionReRendersWithout500AndSendsNothing(): void
    {
        $crawler = $this->client->request('GET', '/contact');
        $form = $crawler->selectButton('Send Message')->form([
            'contact[name]' => '',
            'contact[email]' => 'not-an-email',
            'contact[subject]' => '',
            'contact[message]' => '',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);
    }

    public function testValidSubmissionQueuesContactEmail(): void
    {
        $crawler = $this->client->request('GET', '/contact');
        $form = $crawler->selectButton('Send Message')->form([
            'contact[name]' => 'Tester',
            'contact[email]' => 'tester@example.com',
            'contact[subject]' => 'Hello',
            'contact[message]' => 'This is a sufficiently long test message.',
        ]);
        $this->client->submit($form, ['g-recaptcha-response' => FakeRecaptchaRequestMethod::VALID_TOKEN]);

        self::assertResponseRedirects('/contact');
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        \assert($email instanceof Email);
        self::assertStringContainsString('Hello', (string) $email->getSubject());
    }

    public function testFailedRecaptchaRedirectsWithoutSending(): void
    {
        $crawler = $this->client->request('GET', '/contact');
        $form = $crawler->selectButton('Send Message')->form([
            'contact[name]' => 'Tester',
            'contact[email]' => 'tester@example.com',
            'contact[subject]' => 'Hello',
            'contact[message]' => 'This is a sufficiently long test message.',
        ]);
        $this->client->submit($form, ['g-recaptcha-response' => 'bogus-token']);

        self::assertResponseRedirects('/contact');
        self::assertEmailCount(0);
    }
}
