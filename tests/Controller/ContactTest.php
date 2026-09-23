<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\DatabaseTestCase;
use App\Tests\Double\FakeReCaptcha;
use ReCaptcha\ReCaptcha;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

/**
 * The contact form delivers to the maintainer's inbox only when the payload
 * validates and the reCAPTCHA check passes. Google is never contacted: the
 * ReCaptcha service is replaced with FakeReCaptcha per test.
 */
final class ContactTest extends DatabaseTestCase
{
    use MailerAssertionsTrait;

    protected function setUp(): void
    {
        parent::setUp();

        // The kernel reboots between requests by default, which would drop
        // the FakeReCaptcha installed in the container below. Disabling the
        // reboot keeps one container (and one entity manager) for the test.
        $this->client->disableReboot();
    }

    public function testContactPageRenders(): void
    {
        $this->client->request('GET', '/contact');

        self::assertResponseIsSuccessful();
    }

    public function testValidSubmissionWithSuccessfulRecaptchaSendsEmail(): void
    {
        static::getContainer()->set(ReCaptcha::class, new FakeReCaptcha(true));

        $crawler = $this->client->request('GET', '/contact');
        $form = $crawler->selectButton('Send Message')->form([
            'contact[name]' => 'Jane Doe',
            'contact[email]' => 'jane@example.com',
            'contact[subject]' => 'A question',
            'contact[message]' => 'How do yearly totals work?',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/contact');
        self::assertEmailCount(1);

        $email = $this->getMailerMessage(0);
        self::assertEmailSubjectContains($email, 'PaySubscriptions Contact: A question');
    }

    public function testFailedRecaptchaSendsNothing(): void
    {
        static::getContainer()->set(ReCaptcha::class, new FakeReCaptcha(false));

        $crawler = $this->client->request('GET', '/contact');
        $form = $crawler->selectButton('Send Message')->form([
            'contact[name]' => 'Jane Doe',
            'contact[email]' => 'jane@example.com',
            'contact[subject]' => 'A question',
            'contact[message]' => 'How do yearly totals work?',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/contact');
        self::assertEmailCount(0);
    }
}
