<?php

declare(strict_types=1);

namespace Jobcelis\Tests;

use Jobcelis\Client;
use Jobcelis\JobcelisException;
use PHPUnit\Framework\TestCase;

final class RetryTest extends TestCase
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** @var resource|null */
    private $process = null;
    private string $dir = '';

    protected function tearDown(): void
    {
        $this->stopServer();
    }

    public function testGetIsRetriedOn503UntilItSucceeds(): void
    {
        $client = $this->client([['status' => 503], ['status' => 503], $this->ok(['id' => 'evt_1'])]);

        $this->assertSame(['id' => 'evt_1'], $client->getEvent('evt_1'));
        $this->assertCount(3, $this->requests());
    }

    public function testTooManyRequestsWithRetryAfterIsRetried(): void
    {
        $client = $this->client([['status' => 429, 'headers' => ['Retry-After' => '0']], $this->ok()]);

        $client->listWebhooks();
        $this->assertCount(2, $this->requests());
    }

    public function testSendEventReusesTheGeneratedIdempotencyKey(): void
    {
        $client = $this->client([['status' => 502], 'drop', $this->ok()]);

        $client->sendEvent('order.created', ['id' => 1]);

        $keys = array_map(fn ($r) => $r['headers']['x-idempotency-key'], $this->requests());
        $this->assertCount(3, $keys);
        $this->assertMatchesRegularExpression(self::UUID, $keys[0]);
        $this->assertSame([$keys[0]], array_values(array_unique($keys)));
    }

    public function testSendEventKeepsTheCallerIdempotencyKey(): void
    {
        $client = $this->client([['status' => 503], $this->ok()]);

        $client->sendEvent('order.created', ['id' => 1], ['idempotency_key' => 'order-1']);

        $keys = array_map(fn ($r) => $r['headers']['x-idempotency-key'], $this->requests());
        $this->assertSame(['order-1', 'order-1'], $keys);
    }

    public function testSendEventsAddsMissingIdempotencyKeysAndKeepsExistingOnes(): void
    {
        $client = $this->client([['status' => 503], $this->ok()]);

        $client->sendEvents([
            ['topic' => 'a', 'payload' => []],
            ['topic' => 'b', 'payload' => [], 'idempotency_key' => 'mine'],
        ]);

        $bodies = array_map(fn ($r) => json_decode($r['body'], true)['events'], $this->requests());
        $this->assertCount(2, $bodies);
        $this->assertSame($bodies[0], $bodies[1]);
        $this->assertMatchesRegularExpression(self::UUID, $bodies[0][0]['idempotency_key']);
        $this->assertSame('mine', $bodies[0][1]['idempotency_key']);
    }

    public function testPostWithoutIdempotencyKeyIsNotRetriedOnNetworkError(): void
    {
        $client = $this->client(['drop', $this->ok()]);

        try {
            $client->createWebhook('https://example.com');
            $this->fail('Expected a JobcelisException');
        } catch (JobcelisException $e) {
            $this->assertSame(0, $e->statusCode);
        }
        $this->assertCount(1, $this->requests());
    }

    public function testPostWithoutIdempotencyKeyIsRetriedOn503(): void
    {
        $client = $this->client([['status' => 503], $this->ok()]);

        $client->createWebhook('https://example.com');
        $this->assertCount(2, $this->requests());
    }

    public function testGetIsRetriedOnNetworkError(): void
    {
        $client = $this->client(['drop', $this->ok()]);

        $client->getEvent('evt_1');
        $this->assertCount(2, $this->requests());
    }

    public function testMaxRetriesZeroMakesExactlyOneRequest(): void
    {
        $client = $this->client([['status' => 503], $this->ok()], maxRetries: 0);

        try {
            $client->getEvent('evt_1');
            $this->fail('Expected a JobcelisException');
        } catch (JobcelisException $e) {
            $this->assertSame(503, $e->statusCode);
        }
        $this->assertCount(1, $this->requests());
    }

    public function testThrowsTheUsualErrorAfterTheLastRetry(): void
    {
        $client = $this->client(array_fill(0, 4, ['status' => 503, 'body' => ['error' => 'down']]), maxRetries: 3);

        try {
            $client->getEvent('evt_1');
            $this->fail('Expected a JobcelisException');
        } catch (JobcelisException $e) {
            $this->assertSame(503, $e->statusCode);
            $this->assertSame('down', $e->detail);
        }
        $this->assertCount(4, $this->requests());
    }

    public function testNonRetryableStatusesAreNotRetried(): void
    {
        foreach ([400, 500] as $status) {
            $client = $this->client([['status' => $status, 'body' => ['error' => 'no']], $this->ok()]);

            try {
                $client->getEvent('evt_1');
                $this->fail('Expected a JobcelisException');
            } catch (JobcelisException $e) {
                $this->assertSame($status, $e->statusCode);
            }
            $this->assertCount(1, $this->requests());
            $this->stopServer();
        }
    }

    private function ok(array $body = ['ok' => true]): array
    {
        return ['status' => 200, 'body' => $body];
    }

    private function client(array $responses, int $maxRetries = 6): Client
    {
        $this->dir = sys_get_temp_dir() . '/jobcelis-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents("{$this->dir}/responses.json", json_encode($responses));
        touch("{$this->dir}/requests.log");

        $this->process = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/fake_server.php', "{$this->dir}/responses.json", "{$this->dir}/requests.log", "{$this->dir}/port"],
            [],
            $pipes,
        );

        $deadline = microtime(true) + 10;
        while (($port = trim((string) @file_get_contents("{$this->dir}/port"))) === '') {
            if (microtime(true) > $deadline) {
                $this->fail('Fake server did not start');
            }
            usleep(10_000);
        }

        return new Client('key', "http://127.0.0.1:{$port}", maxRetries: $maxRetries, retryBaseDelay: 0.001);
    }

    private function requests(): array
    {
        $lines = file("{$this->dir}/requests.log", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_map(fn ($line) => json_decode($line, true), $lines ?: []);
    }

    private function stopServer(): void
    {
        if ($this->process !== null) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        if ($this->dir !== '' && is_dir($this->dir)) {
            array_map('unlink', glob("{$this->dir}/*") ?: []);
            rmdir($this->dir);
            $this->dir = '';
        }
    }
}
