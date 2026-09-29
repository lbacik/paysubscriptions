<?php

declare(strict_types=1);

namespace App\Tests\Privacy;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Guards the external-service audit (issue #47).
 *
 * The verified inventory lives in docs/third-party-services.md. These checks
 * pin its load-time claims: no signed-in avatar (or any other asset request)
 * may send a User's email to a third party, and any new automatic request or
 * outbound link added to a Twig template, the reCAPTCHA controller, or the
 * three transactional email templates fails here until the inventory
 * documents it. Other request sources (other JavaScript, backend/PHP-issued
 * HTTP calls, form actions, CSS `@import`) are not scanned.
 */
final class ExternalServicesTest extends WebTestCase
{
    /**
     * Third-party hosts a page is allowed to fetch automatically (script, stylesheet,
     * image, font, preconnect), mirroring docs/third-party-services.md.
     */
    private const ALLOWED_FETCH_HOSTS = [
        'cdnjs.cloudflare.com',
        'cloud.umami.is',
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'www.google.com', // reCAPTCHA loader: contact page and footer signup form
    ];

    /**
     * Third-party hosts that may only appear as plain links (no automatic request;
     * data is disclosed only when the visitor deliberately follows them).
     */
    private const ALLOWED_LINK_HOSTS = [
        'gprodb.com',
        'www.buymeacoffee.com',
    ];

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
    }

    protected function tearDown(): void
    {
        parent::tearDown();

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
    }

    public function testNoTemplateSendsUserDataToAThirdParty(): void
    {
        $violations = [];

        foreach (self::twigFiles() as $path => $contents) {
            foreach (['robohash', 'gravatar'] as $avatarHost) {
                if (str_contains(strtolower($contents), $avatarHost)) {
                    $violations[] = \sprintf('%s references a third-party avatar service (%s)', $path, $avatarHost);
                }
            }

            foreach (self::urlAttributeValues($contents) as $url) {
                if (str_contains($url, 'app.user')) {
                    $violations[] = \sprintf('%s embeds user data in an asset URL: %s', $path, $url);
                }
            }
        }

        self::assertSame([], $violations);
    }

    public function testTransactionalEmailTemplatesContainNoExternalUrls(): void
    {
        $templates = [
            'contact/contact_email.html.twig',
            'registration/confirmation_email.html.twig',
            'reset_password/email.html.twig',
        ];

        foreach ($templates as $relative) {
            $contents = file_get_contents(\dirname(__DIR__, 2).'/templates/'.$relative);
            self::assertIsString($contents, \sprintf('%s must exist', $relative));
            self::assertDoesNotMatchRegularExpression(
                '/https?:\/\//i',
                $contents,
                \sprintf('%s must not phone home to any external URL (no tracking pixels, no hosted assets).', $relative),
            );
        }
    }

    public function testExternalRequestsMatchTheInventoriedServices(): void
    {
        $fetchHosts = [];
        $linkHosts = [];

        foreach (self::twigFiles() as $path => $contents) {
            $fetchHosts = [...$fetchHosts, ...self::hostsInFetchContext($path, $contents)];
            $linkHosts = [...$linkHosts, ...self::hostsInAnchorHrefs($contents)];
        }

        // The reCAPTCHA loader lives in JavaScript rather than Twig: the Stimulus
        // controller injects it only when the contact form connects.
        $recaptcha = self::recaptchaControllerSource();
        self::assertStringContainsString(
            'https://www.google.com/recaptcha/api.js',
            $recaptcha,
            'The contact form must keep loading reCAPTCHA from its inventoried URL.',
        );
        $fetchHosts[] = 'www.google.com';

        $fetchHosts = array_values(array_unique($fetchHosts));
        $linkHosts = array_values(array_unique($linkHosts));
        sort($fetchHosts);
        sort($linkHosts);

        $expectedFetch = self::ALLOWED_FETCH_HOSTS;
        sort($expectedFetch);
        $expectedLink = self::ALLOWED_LINK_HOSTS;
        sort($expectedLink);

        self::assertSame($expectedFetch, $fetchHosts, 'Automatic third-party requests drifted from docs/third-party-services.md.');
        self::assertSame($expectedLink, $linkHosts, 'Outbound third-party links drifted from docs/third-party-services.md.');
    }

    public function testSignedInAvatarUsesNoExternalUrl(): void
    {
        $menu = file_get_contents(\dirname(__DIR__, 2).'/templates/partials/_user_profile_menu.html.twig');
        self::assertIsString($menu);
        self::assertDoesNotMatchRegularExpression('/https?:\/\//i', $menu, 'The signed-in user menu must not reference any external URL.');
    }

    public function testSignedInUserMenuRendersWithoutExternalAvatar(): void
    {
        self::bootKernel();
        $user = (new User())->setEmail('subscriber@example.com');

        $container = static::getContainer();
        $tokenStorage = $container->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
        $tokenStorage->setToken(new PostAuthenticationToken($user, 'main', $user->getRoles()));

        $html = $container->get('twig')->render('partials/_user_profile_menu.html.twig');

        self::assertStringNotContainsString('http', $html);
        self::assertStringNotContainsString('robohash.org', $html);
        // The menu still identifies the account first-party (dropdown text)
        // and shows a local initial avatar instead of a fetched image.
        self::assertStringContainsString('subscriber@example.com', $html);
        self::assertStringContainsString('>S<', $html);
    }

    public function testPublicHomePageLoadsOnlyInventoriedServices(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('cloud.umami.is', $content);
        self::assertStringNotContainsString('robohash.org', $content);
        self::assertStringNotContainsString('cdn.buymeacoffee.com', $content);
    }

    public function testPricingPageLinksSupportWithoutAnExternalImageRequest(): void
    {
        $client = static::createClient();
        $client->request('GET', '/pricing');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('https://www.buymeacoffee.com/lbacik', $content);
        self::assertStringNotContainsString('cdn.buymeacoffee.com', $content);
    }

    public function testContactPageWiresRecaptchaWithoutOtherThirdParties(): void
    {
        // Dummy keys: the page must render its reCAPTCHA-wired form without
        // real Google credentials (verification only happens on POST).
        // Set as process environment because %env()% resolves at runtime,
        // not from the BrowserKit server parameters.
        putenv('GOOGLE_RECAPTCHA_SITE_KEY=test-site-key');
        putenv('GOOGLE_RECAPTCHA_SECRET=test-secret');
        $_SERVER['GOOGLE_RECAPTCHA_SITE_KEY'] = 'test-site-key';
        $_SERVER['GOOGLE_RECAPTCHA_SECRET'] = 'test-secret';
        $_ENV['GOOGLE_RECAPTCHA_SITE_KEY'] = 'test-site-key';
        $_ENV['GOOGLE_RECAPTCHA_SECRET'] = 'test-secret';

        $client = static::createClient();
        $client->request('GET', '/contact');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-controller="recaptcha"', $content);
        self::assertStringNotContainsString('robohash.org', $content);
    }

    /**
     * @return array<string,string> relative path => contents for every Twig template
     */
    private static function twigFiles(): array
    {
        $templatesDir = \dirname(__DIR__, 2).'/templates';
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templatesDir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'twig') {
                $relative = substr($file->getPathname(), \strlen($templatesDir) + 1);
                $contents = file_get_contents($file->getPathname());
                self::assertIsString($contents);
                $files[$relative] = $contents;
            }
        }

        self::assertNotEmpty($files);

        return $files;
    }

    /**
     * @return list<string> hosts of automatic subresource requests (src=, srcset=, stylesheet/preconnect links)
     */
    private static function hostsInFetchContext(string $path, string $contents): array
    {
        $hosts = [];

        foreach (self::urlAttributeValues($contents, ['src', 'srcset']) as $url) {
            foreach (preg_split('/\s*,\s*/', $url) as $candidate) {
                $host = self::absoluteHost(trim(explode(' ', trim($candidate))[0]));
                if ($host !== null) {
                    $hosts[] = $host;
                }
            }
        }

        if (preg_match_all('/<link\b[^>]*href\s*=\s*["\']([^"\']*)["\'][^>]*>/i', $contents, $matches)) {
            foreach ($matches[1] as $url) {
                $host = self::absoluteHost($url);
                if ($host !== null) {
                    $hosts[] = $host;
                }
            }
        }

        return $hosts;
    }

    /**
     * @return list<string> hosts of plain anchor links (no automatic request)
     */
    private static function hostsInAnchorHrefs(string $contents): array
    {
        $hosts = [];

        if (preg_match_all('/<a\b[^>]*href\s*=\s*["\']([^"\']*)["\'][^>]*>/i', $contents, $matches)) {
            foreach ($matches[1] as $url) {
                $host = self::absoluteHost($url);
                if ($host !== null) {
                    $hosts[] = $host;
                }
            }
        }

        return $hosts;
    }

    /**
     * @param list<string> $attributes
     *
     * @return list<string> every URL held by the given attributes (either quote
     *                     style) plus any CSS url(...) reference, so a leak cannot
     *                     hide behind quoting or embedding choices
     */
    private static function urlAttributeValues(string $contents, array $attributes = ['src', 'href', 'srcset']): array
    {
        $urls = [];
        $pattern = '/(?:'.implode('|', $attributes).')\s*=\s*["\']([^"\']*)["\']/i';

        if (preg_match_all($pattern, $contents, $matches)) {
            $urls = [...$urls, ...$matches[1]];
        }

        if (preg_match_all('/url\(\s*["\']?([^)"\']+)/i', $contents, $matches)) {
            $urls = [...$urls, ...$matches[1]];
        }

        return $urls;
    }

    private static function absoluteHost(string $url): ?string
    {
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        if (!preg_match('/^https?:\/\//i', $url)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return \is_string($host) ? strtolower($host) : null;
    }

    private static function recaptchaControllerSource(): string
    {
        $source = file_get_contents(\dirname(__DIR__, 2).'/assets/controllers/recaptcha_controller.js');
        self::assertIsString($source);

        return $source;
    }
}
