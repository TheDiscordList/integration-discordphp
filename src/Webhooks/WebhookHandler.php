<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Webhooks;

use Closure;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * PSR-15 request handler for a TheDiscordList webhook endpoint.
 *
 * Answers 204 once `$onEvent` returns (for every event name, including
 * `webhook.ping` and ones this version does not know), 400 when verification
 * fails, 405 for anything but POST, and 500 when `$onEvent` throws so the
 * delivery is retried. It is also invokable, so ReactPHP's `HttpServer` takes it
 * as-is.
 */
final class WebhookHandler implements RequestHandlerInterface
{
    /** @var Closure(array<string, mixed>, array{delivery: ?string, attempt: int}): mixed */
    private readonly Closure $onEvent;

    /** @var ?Closure(Throwable): mixed */
    private readonly ?Closure $onError;

    /**
     * @param  callable(array<string, mixed>, array{delivery: ?string, attempt: int}): mixed  $onEvent
     * @param  ?callable(Throwable): mixed  $onError  Called for every rejected delivery and every `$onEvent` throw.
     */
    public function __construct(
        private readonly string $secret,
        callable $onEvent,
        private readonly ResponseFactoryInterface $responses,
        private readonly int $tolerance = Verifier::DEFAULT_TOLERANCE,
        ?callable $onError = null,
    ) {
        $this->onEvent = $onEvent(...);
        $this->onError = $onError === null ? null : $onError(...);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return $this->responses->createResponse(405)->withHeader('Allow', 'POST');
        }

        try {
            $event = Verifier::verify(
                (string) $request->getBody(),
                $request->getHeaderLine(Verifier::SIGNATURE_HEADER),
                $this->secret,
                $this->tolerance,
            );
        } catch (VerificationException $error) {
            $this->report($error);

            return $this->responses->createResponse(400);
        }

        $delivery = $request->getHeaderLine(Verifier::DELIVERY_HEADER);
        $context = [
            'delivery' => $delivery === '' ? null : $delivery,
            'attempt' => max(1, (int) $request->getHeaderLine(Verifier::ATTEMPT_HEADER)),
        ];

        try {
            ($this->onEvent)($event, $context);
        } catch (Throwable $error) {
            $this->report($error);

            return $this->responses->createResponse(500);
        }

        return $this->responses->createResponse(204);
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request);
    }

    private function report(Throwable $error): void
    {
        if ($this->onError !== null) {
            ($this->onError)($error);
        }
    }
}
