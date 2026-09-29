<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\DatabaseTestCase;
use App\Tests\RateLimitTestHelper;
use Symfony\Component\HttpFoundation\Response;

/**
 * Abuse protection for the public OAuth2 token endpoint (issue #143).
 *
 * Token requests are throttled per client_id and per source IP: once either
 * burst is exhausted the endpoint answers 429 (with Retry-After) instead of
 * reaching the authorization server. The requests below carry a bogus grant
 * on purpose — the limiter runs before the OAuth layer, so abuse is stopped
 * before any cryptographic work happens.
 */
final class OAuthTokenRateLimitTest extends DatabaseTestCase
{
    use RateLimitTestHelper;

    private const CLIENT_ID = 'ratelimit-cli';

    protected function setUp(): void
    {
        parent::setUp();

        // Start every test with full bursts: limiter state survives in the
        // cache across runs, and the client/IP keys below are fixed. The
        // empty client key covers keyless requests from before the listener
        // learned to skip the per-client window for them.
        $this->resetLimiter('limiter.oauth_token_client', self::CLIENT_ID);
        $this->resetLimiter('limiter.oauth_token_client', '');
        $this->resetLimiter('limiter.oauth_token_ip', '127.0.0.1');
    }

    protected function tearDown(): void
    {
        $this->restoreRateLimitEnv();

        parent::tearDown();
    }

    public function testTokenRequestsAreLimitedPerClient(): void
    {
        // Opt in: two token requests per client. Factories instantiate lazily
        // on the first request, so pinning here still wins.
        $this->pinRateLimitEnv(['RATE_LIMIT_TOKEN_CLIENT' => '2', 'RATE_LIMIT_TOKEN_IP' => '100000']);

        $this->postToken();
        $this->assertNotThrottled();
        $this->postToken();
        $this->assertNotThrottled();

        $this->postToken();
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertNotNull(
            $this->client->getResponse()->headers->get('Retry-After'),
            'throttled token responses must tell the caller when to retry'
        );
    }

    public function testTokenRequestsAreLimitedPerIp(): void
    {
        $this->pinRateLimitEnv(['RATE_LIMIT_TOKEN_CLIENT' => '100000', 'RATE_LIMIT_TOKEN_IP' => '2']);

        $this->postToken();
        $this->assertNotThrottled();
        $this->postToken();
        $this->assertNotThrottled();

        $this->postToken();
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testNonPostTokenRequestsDoNotConsumeBudget(): void
    {
        // Only POSTs count: a GET must leave the burst of 1 untouched, so the
        // following POST is still accepted instead of throttled.
        $this->pinRateLimitEnv(['RATE_LIMIT_TOKEN_CLIENT' => '1', 'RATE_LIMIT_TOKEN_IP' => '1']);

        $this->client->request('GET', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => 'bogus-token',
        ]);
        self::assertNotSame(
            Response::HTTP_TOO_MANY_REQUESTS,
            $this->client->getResponse()->getStatusCode(),
            'non-POST token requests must never be throttled'
        );

        $this->postToken();
        $this->assertNotThrottled();
    }

    public function testKeylessTokenRequestsShareNoClientBucket(): void
    {
        // Requests without a client_id skip the per-client window (there is
        // no client to attribute them to): two of them must both pass even
        // with a client burst of 1, or keyless scripts could lock each other
        // out through a shared anonymous bucket.
        $this->pinRateLimitEnv(['RATE_LIMIT_TOKEN_CLIENT' => '1', 'RATE_LIMIT_TOKEN_IP' => '100000']);

        $this->postKeylessToken();
        $this->assertNotThrottled();
        $this->postKeylessToken();
        $this->assertNotThrottled();
    }

    private function postToken(): void
    {
        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => 'bogus-token',
        ]);
    }

    private function postKeylessToken(): void
    {
        $this->client->request('POST', '/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => 'bogus-token',
        ]);
    }

    private function assertNotThrottled(): void
    {
        $status = $this->client->getResponse()->getStatusCode();
        self::assertGreaterThanOrEqual(400, $status, 'bogus grant must fail at the OAuth layer');
        self::assertLessThan(500, $status, 'bogus grant must fail at the OAuth layer');
        self::assertNotSame(Response::HTTP_TOO_MANY_REQUESTS, $status);
    }
}
