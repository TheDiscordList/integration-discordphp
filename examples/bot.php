<?php

declare(strict_types=1);

/*
 * DISCORD_TOKEN=… TDL_TOKEN=… php examples/bot.php
 *
 * Set TDL_WEBHOOK_SECRET (and optionally PORT) to also receive TheDiscordList
 * webhooks on the bot's own event loop.
 */

require __DIR__.'/../vendor/autoload.php';

use Discord\Discord;
use Discord\WebSockets\Intents;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use TheDiscordList\DiscordPHP\Autoposter;
use TheDiscordList\DiscordPHP\PublishException;
use TheDiscordList\DiscordPHP\Webhooks\VerificationException;
use TheDiscordList\DiscordPHP\Webhooks\Verifier;

$discord = new Discord([
    'token' => (string) getenv('DISCORD_TOKEN'),
    'intents' => Intents::getDefaultIntents(),
]);
$logger = $discord->getLogger();

$poster = Autoposter::attach($discord, getenv('TDL_TOKEN') ?: null);

$poster->on('posted', fn (string $type, int $status) => $logger->info("TheDiscordList: {$type} posted ({$status})"));
$poster->on('error', fn (PublishException $error) => $logger->warning("TheDiscordList: {$error->getMessage()}"));
$poster->on('disabled', fn (string $type, ?int $status, string $reason) => $logger->warning("TheDiscordList: {$type} disabled ({$reason})"));

$secret = getenv('TDL_WEBHOOK_SECRET');
if (is_string($secret) && $secret !== '') {
    $server = new HttpServer($discord->getLoop(), function (ServerRequestInterface $request) use ($secret, $logger): Response {
        try {
            $event = Verifier::verify((string) $request->getBody(), $request->getHeaderLine('X-TDL-Signature'), $secret);
        } catch (VerificationException $error) {
            $logger->warning("TheDiscordList webhook rejected: {$error->reason->value}");

            return new Response(400);
        }

        if ($event['event'] === 'listing.upvoted') {
            $logger->info('New upvote on TheDiscordList.');
        }

        // Every event, including webhook.ping and names this version does not know, gets a 2xx.
        return new Response(204);
    });
    $server->listen(new SocketServer('0.0.0.0:'.(getenv('PORT') ?: '8080'), [], $discord->getLoop()));
}

$discord->run();
