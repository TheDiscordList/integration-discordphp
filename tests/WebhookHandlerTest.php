<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Tests;

use Closure;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use TheDiscordList\DiscordPHP\Tests\Support\FakeLoop;
use TheDiscordList\DiscordPHP\Webhooks\ErrorCode;
use TheDiscordList\DiscordPHP\Webhooks\VerificationException;
use TheDiscordList\DiscordPHP\Webhooks\WebhookHandler;
use Throwable;

use function React\Promise\resolve;

final class WebhookHandlerTest extends TestCase
{
    private const SECRET = 'whsec_test';

    /** @var list<array{array<string, mixed>, array{delivery: ?string, attempt: int}}> */
    private array $received = [];

    /** @var list<Throwable> */
    private array $errors = [];

    public function test_ping_is_acknowledged_with_204_and_context(): void
    {
        $response = $this->handler()->handle($this->request($this->body('webhook.ping'), [
            'X-TDL-Delivery' => 'abc-123',
            'X-TDL-Attempt' => '3',
            'X-TDL-Event' => 'webhook.ping',
        ]));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('webhook.ping', $this->received[0][0]['event']);
        $this->assertSame(['delivery' => 'abc-123', 'attempt' => 3], $this->received[0][1]);
    }

    public function test_unknown_events_still_get_a_2xx(): void
    {
        $response = $this->handler()->handle($this->request($this->body('something.new')));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(['delivery' => null, 'attempt' => 1], $this->received[0][1]);
    }

    public function test_bad_signature_is_401_and_reported(): void
    {
        $body = $this->body('listing.upvoted');
        $request = $this->request($body)->withHeader('X-TDL-Signature', 't='.time().',v1=deadbeef');

        $this->assertSame(401, $this->handler()->handle($request)->getStatusCode());
        $this->assertSame([], $this->received);
        $this->assertInstanceOf(VerificationException::class, $this->errors[0]);
        $this->assertSame(ErrorCode::InvalidSignature, $this->errors[0]->reason);
    }

    public function test_missing_signature_is_401(): void
    {
        $request = $this->request($this->body('listing.bumped'))->withoutHeader('X-TDL-Signature');

        $this->assertSame(401, $this->handler()->handle($request)->getStatusCode());
    }

    public function test_malformed_signature_is_400(): void
    {
        $request = $this->request($this->body('listing.bumped'))->withHeader('X-TDL-Signature', 'garbage');

        $this->assertSame(400, $this->handler()->handle($request)->getStatusCode());
    }

    public function test_a_blank_secret_is_refused_up_front(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WebhookHandler('  ', fn () => null, new HttpFactory);
    }

    public function test_a_body_over_1_mib_is_413_and_never_verified(): void
    {
        $response = $this->handler()->handle($this->request(str_repeat('x', WebhookHandler::MAX_BODY_BYTES + 1)));

        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame([], $this->received);
        $this->assertCount(1, $this->errors);
    }

    public function test_only_post_is_allowed(): void
    {
        $response = $this->handler()->handle(new ServerRequest('GET', '/tdl'));

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('POST', $response->getHeaderLine('Allow'));
    }

    public function test_a_throwing_handler_answers_500_so_the_delivery_is_retried(): void
    {
        $handler = new WebhookHandler(self::SECRET, function (): void {
            throw new RuntimeException('database down');
        }, new HttpFactory, onError: function (Throwable $error): void {
            $this->errors[] = $error;
        });

        $this->assertSame(500, $handler->handle($this->request($this->body('listing.bumped')))->getStatusCode());
        $this->assertSame('database down', $this->errors[0]->getMessage());
    }

    public function test_it_is_invokable_for_reactphp(): void
    {
        $response = ($this->handler())($this->request($this->body('webhook.ping')));

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(204, $response->getStatusCode());
    }

    public function test_a_promise_is_awaited_within_the_budget(): void
    {
        $loop = new FakeLoop;
        $work = new Deferred;
        $handler = $this->promising($loop, $work->promise());
        $status = $this->status($handler($this->request($this->body('listing.upvoted'))));

        $loop->advance(7.9);
        $this->assertNull($status());

        $work->resolve(null);
        $this->assertSame(204, $status());
        $this->assertSame(0, $loop->timerCount());
    }

    public function test_a_rejected_promise_answers_500_and_is_reported(): void
    {
        $loop = new FakeLoop;
        $work = new Deferred;
        $handler = $this->promising($loop, $work->promise());
        $status = $this->status($handler($this->request($this->body('listing.upvoted'))));

        $work->reject(new RuntimeException('database down'));

        $this->assertSame(500, $status());
        $this->assertSame('database down', $this->errors[0]->getMessage());
    }

    public function test_a_promise_past_the_budget_answers_202_and_a_late_rejection_is_still_reported(): void
    {
        $loop = new FakeLoop;
        $work = new Deferred;
        $handler = $this->promising($loop, $work->promise());
        $status = $this->status($handler($this->request($this->body('listing.upvoted'))));

        $loop->advance(WebhookHandler::DEFAULT_TIMEOUT);
        $this->assertSame(202, $status());

        $work->reject(new RuntimeException('database down'));
        $this->assertSame(202, $status());
        $this->assertSame('database down', $this->errors[0]->getMessage());
    }

    public function test_handle_cannot_wait_so_a_promise_answers_202(): void
    {
        $work = new Deferred;
        $handler = $this->promising(new FakeLoop, $work->promise());

        $this->assertSame(202, $handler->handle($this->request($this->body('listing.upvoted')))->getStatusCode());

        $work->reject(new RuntimeException('database down'));
        $this->assertSame('database down', $this->errors[0]->getMessage());
    }

    private function handler(): WebhookHandler
    {
        return new WebhookHandler(
            self::SECRET,
            function (array $event, array $context): void {
                $this->received[] = [$event, $context];
            },
            new HttpFactory,
            onError: function (Throwable $error): void {
                $this->errors[] = $error;
            },
        );
    }

    /** @param PromiseInterface<mixed> $work */
    private function promising(FakeLoop $loop, PromiseInterface $work): WebhookHandler
    {
        return new WebhookHandler(self::SECRET, fn (): PromiseInterface => $work, new HttpFactory, onError: function (Throwable $error): void {
            $this->errors[] = $error;
        }, loop: $loop);
    }

    /**
     * @param  ResponseInterface|PromiseInterface<ResponseInterface>  $response
     * @return Closure(): ?int The status, or null while the response is pending.
     */
    private function status(ResponseInterface|PromiseInterface $response): Closure
    {
        $status = null;
        resolve($response)->then(function (ResponseInterface $value) use (&$status): void {
            $status = $value->getStatusCode();
        });

        return function () use (&$status): ?int {
            return $status;
        };
    }

    private function body(string $event): string
    {
        return '{"id":"5f0c","event":"'.$event.'","created_at":"2026-10-01T12:00:00+00:00","listing":{"id":"1","type":"bot","name":"Bot","url":"https://thediscordlist.com/bots/1"},"data":{}}';
    }

    /** @param array<string, string> $headers */
    private function request(string $body, array $headers = []): ServerRequest
    {
        $t = time();
        $headers['X-TDL-Signature'] = "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", self::SECRET);

        return new ServerRequest('POST', '/tdl', $headers, $body);
    }
}
