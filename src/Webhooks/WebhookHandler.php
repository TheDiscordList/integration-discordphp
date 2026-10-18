<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Webhooks;

use Closure;
use InvalidArgumentException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

use function React\Promise\race;

/**
 * PSR-15 request handler for a TheDiscordList webhook endpoint.
 *
 * Answers 204 once `$onEvent` returns (for every event name, including
 * `webhook.ping` and ones this version does not know), 401 for a missing or
 * invalid signature, 400 for any other verification failure, 413 for a body
 * over 1 MiB, 405 for anything but POST, and 500 when `$onEvent` throws so the
 * delivery is retried.
 *
 * `$onEvent` may return a ReactPHP promise. Invoked as a ReactPHP `HttpServer`
 * callable, the handler waits up to `$timeout` seconds for it: 204 when it
 * fulfils, 500 when it rejects, 202 when the budget runs out first, so the
 * sender's 10 second timeout is never hit. A synchronous `handle()` cannot wait,
 * so it answers 202 for any promise. A rejection always reaches `$onError`.
 */
final class WebhookHandler implements RequestHandlerInterface
{
    public const MAX_BODY_BYTES = 1_048_576;

    public const DEFAULT_TIMEOUT = 8.0;

    /** @var Closure(array<string, mixed>, array{delivery: ?string, attempt: int}): mixed */
    private readonly Closure $onEvent;

    /** @var ?Closure(Throwable): mixed */
    private readonly ?Closure $onError;

    /**
     * @param  callable(array<string, mixed>, array{delivery: ?string, attempt: int}): mixed  $onEvent
     * @param  ?callable(Throwable): mixed  $onError  Called for every rejected delivery and every `$onEvent` failure.
     * @param  ?LoopInterface  $loop  Times the budget for a promise. Default: ReactPHP's global loop.
     *
     * @throws InvalidArgumentException When `$secret` is blank.
     */
    public function __construct(
        private readonly string $secret,
        callable $onEvent,
        private readonly ResponseFactoryInterface $responses,
        private readonly int $tolerance = Verifier::DEFAULT_TOLERANCE,
        ?callable $onError = null,
        private readonly float $timeout = self::DEFAULT_TIMEOUT,
        private readonly ?LoopInterface $loop = null,
    ) {
        Verifier::assertSecret($secret);
        $this->onEvent = $onEvent(...);
        $this->onError = $onError === null ? null : $onError(...);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->dispatch($request);

        return $response instanceof ResponseInterface ? $response : $this->responses->createResponse(202);
    }

    /** @return ResponseInterface|PromiseInterface<ResponseInterface> */
    public function __invoke(ServerRequestInterface $request): ResponseInterface|PromiseInterface
    {
        $response = $this->dispatch($request);
        if ($response instanceof ResponseInterface) {
            return $response;
        }

        $loop = $this->loop ?? Loop::get();
        /** @var Deferred<ResponseInterface> $budget */
        $budget = new Deferred;
        $timer = $loop->addTimer($this->timeout, fn () => $budget->resolve($this->responses->createResponse(202)));

        return race([$response->finally(fn () => $loop->cancelTimer($timer)), $budget->promise()]);
    }

    /** @return ResponseInterface|PromiseInterface<ResponseInterface> A promise when `$onEvent` returned one. */
    private function dispatch(ServerRequestInterface $request): ResponseInterface|PromiseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return $this->responses->createResponse(405)->withHeader('Allow', 'POST');
        }

        $body = (string) $request->getBody();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            $this->report(new RuntimeException('Webhook body is larger than 1 MiB.'));

            return $this->responses->createResponse(413);
        }

        try {
            $event = Verifier::verify($body, $request->getHeaderLine(Verifier::SIGNATURE_HEADER), $this->secret, $this->tolerance);
        } catch (VerificationException $error) {
            $this->report($error);

            return $this->responses->createResponse(
                in_array($error->reason, [ErrorCode::MissingSignature, ErrorCode::InvalidSignature], true) ? 401 : 400,
            );
        }

        $delivery = $request->getHeaderLine(Verifier::DELIVERY_HEADER);
        $context = [
            'delivery' => $delivery === '' ? null : $delivery,
            'attempt' => max(1, (int) $request->getHeaderLine(Verifier::ATTEMPT_HEADER)),
        ];

        try {
            $result = ($this->onEvent)($event, $context);
        } catch (Throwable $error) {
            $this->report($error);

            return $this->responses->createResponse(500);
        }
        if (! $result instanceof PromiseInterface) {
            return $this->responses->createResponse(204);
        }

        return $result->then(
            fn (): ResponseInterface => $this->responses->createResponse(204),
            function (Throwable $error): ResponseInterface {
                $this->report($error);

                return $this->responses->createResponse(500);
            },
        );
    }

    private function report(Throwable $error): void
    {
        if ($this->onError !== null) {
            ($this->onError)($error);
        }
    }
}
