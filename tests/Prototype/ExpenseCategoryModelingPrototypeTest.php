<?php

declare(strict_types=1);

namespace App\Tests\Prototype;

use App\Entity\ExpenseCategory;
use App\Service\ExpenseCategoryService;
use App\Tests\Controller\Api\V1\ExpenseCategoryApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PROTOTYPE (#246): drives the same ExpenseCategory scenario through the
 * three modeling variants and writes a request/response transcript per
 * variant to tests/Prototype/transcripts/<variant>.md. Not a test suite: it asserts
 * nothing about behavior, the transcripts are the output.
 *
 *   APP_ENV=test DATABASE_URL=... vendor/bin/phpunit tests/Prototype
 */
final class ExpenseCategoryModelingPrototypeTest extends ExpenseCategoryApiTestCase
{
    private const USER = 'proto-user@example.com';
    private const OTHER = 'proto-other@example.com';

    /** @var list<string> */
    private array $log = [];

    /**
     * @return iterable<string, array{string}>
     */
    public static function variants(): iterable
    {
        yield 'A entity' => ['proto-entity'];
        yield 'B dto' => ['proto-dto'];
        yield 'C mapped' => ['proto-mapped'];
    }

    #[DataProvider('variants')]
    public function testScenario(string $variant): void
    {
        $this->user = $this->createUser(self::USER);
        $this->other = $this->createUser(self::OTHER);
        $this->registerPublicClient();

        $service = static::getContainer()->get(ExpenseCategoryService::class);
        $rent = $service->create($this->user, 'Rent', '#111111');
        // createSubscription() puts a Subscription in the default category.
        $this->createSubscription($this->user);
        $inUse = $service->findDefaultCategory($this->user);
        \assert($inUse instanceof ExpenseCategory);
        $foreign = $service->create($this->other, 'Secret', '#222222');

        $base = '/api/v1/'.$variant.'/expense-categories';
        $merge = 'application/merge-patch+json';

        $this->log[] = '# Variant '.$variant;
        $this->log[] = sprintf("Fixtures: %s owns `Rent` (%s, in use by one Subscription: %s); %s owns `Secret` (%s).\n",
            self::USER, $rent->getId(), $inUse->getName(), self::OTHER, $foreign->getId());

        $created = $this->call('1. create', 'POST', $base, '{"name":"Food","color":"#ff0000"}');
        $foodId = $created['id'] ?? 'missing';

        $this->call('2. create duplicate name', 'POST', $base, '{"name":"Rent","color":"#ff0000"}');
        $this->call('3. create invalid fields', 'POST', $base, '{"name":"F","color":"red"}');
        $this->call('4. create malformed JSON', 'POST', $base, '{"name":');
        $this->call('5. create wrong type', 'POST', $base, '{"name":123,"color":"#000000"}');
        $this->call('6. create with read-only/unknown fields', 'POST', $base, '{"name":"Travel","color":"#123456","id":"'.$foreign->getId().'","createdAt":"2000-01-01T00:00:00+00:00","owner":"x"}');
        $this->call('7. create name taken only by another User', 'POST', $base, '{"name":"Secret","color":"#123456"}');

        $this->call('8. list (no Accept: HAL default)', 'GET', $base);
        $this->call('9. list as application/json', 'GET', $base, null, self::USER, ['HTTP_ACCEPT' => 'application/json']);
        $this->call('10. list as application/ld+json', 'GET', $base, null, self::USER, ['HTTP_ACCEPT' => 'application/ld+json']);
        $this->call('11. show own', 'GET', $base.'/'.$foodId);
        $this->call('12. show foreign', 'GET', $base.'/'.$foreign->getId());
        $this->call('13. show malformed id', 'GET', $base.'/not-a-uuid');

        $this->call('14. patch color only', 'PATCH', $base.'/'.$foodId, '{"color":"#00ff00"}', self::USER, [], $merge);
        $this->call('15. patch to a taken name', 'PATCH', $base.'/'.$foodId, '{"name":"Rent"}', self::USER, [], $merge);
        $this->call('16. patch foreign', 'PATCH', $base.'/'.$foreign->getId(), '{"name":"Mine now"}', self::USER, [], $merge);
        $this->call('17. patch with application/json', 'PATCH', $base.'/'.$foodId, '{"color":"#0000ff"}');
        $this->call('18. patch null on required field', 'PATCH', $base.'/'.$foodId, '{"name":null}', self::USER, [], $merge);
        $this->call('19. patch empty object', 'PATCH', $base.'/'.$foodId, '{}', self::USER, [], $merge);

        $this->call('20. delete category in use', 'DELETE', $base.'/'.$inUse->getId());
        $this->call('21. delete foreign', 'DELETE', $base.'/'.$foreign->getId());
        $this->call('22. delete free category', 'DELETE', $base.'/'.$foodId);
        $this->call('23. no token', 'GET', $base, null, null);

        $dir = __DIR__.'/transcripts';
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/'.$variant.'.md', implode("\n", $this->log)."\n");

        self::assertTrue(true);
    }

    /**
     * @param array<string, string> $server
     *
     * @return array<string, mixed>
     */
    private function call(string $label, string $method, string $uri, ?string $body = null, ?string $as = self::USER, array $server = [], string $contentType = 'application/json'): array
    {
        if (null !== $body) {
            $server['CONTENT_TYPE'] = $contentType;
        }
        if (null !== $as) {
            $server['HTTP_Authorization'] = 'Bearer '.$this->craftToken($as);
        }

        $this->client->request($method, $uri, [], [], $server, $body);
        $response = $this->client->getResponse();
        $content = (string) $response->getContent();
        $decoded = json_decode($content, true);
        $pretty = \is_array($decoded) ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $content;
        if (\is_array($decoded)) {
            // Stack traces from debug mode are noise for the comparison.
            unset($decoded['trace']);
            $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $this->log[] = sprintf('## %s', $label);
        $this->log[] = sprintf('`%s %s`%s', $method, $uri, null !== $body ? sprintf(' (%s) `%s`', $server['CONTENT_TYPE'], $body) : '');
        $this->log[] = sprintf('→ **%d** `%s`', $response->getStatusCode(), (string) $response->headers->get('Content-Type'));
        if ('' !== $content) {
            $this->log[] = "```json\n".$pretty."\n```";
        }
        $this->log[] = '';

        return \is_array($decoded) ? $decoded : [];
    }
}
