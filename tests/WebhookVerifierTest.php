<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheDiscordList\DiscordPHP\Webhooks\ErrorCode;
use TheDiscordList\DiscordPHP\Webhooks\VerificationException;
use TheDiscordList\DiscordPHP\Webhooks\Verifier;

final class WebhookVerifierTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private const T = 1700000000;

    private const BODY = '{"id":"d1","event":"webhook.ping","created_at":"2023-11-14T22:13:20+00:00","listing":{"id":"1","type":"bot","name":"Bot","url":"https://thediscordlist.com/bots/1"},"data":{"message":"hi"}}';

    /** Computed with the server's PHP formula; shared by every SDK. */
    private const VECTOR_V1 = 'b6eeea31d04c3f86990dcbb706dfaf988c9ff2fc234316ae77e168831127dc59';

    public function test_shared_test_vector(): void
    {
        $v1 = hash_hmac('sha256', self::T.'.'.self::BODY, self::SECRET);

        $this->assertSame(self::VECTOR_V1, $v1);

        $event = Verifier::verify(self::BODY, 't='.self::T.',v1='.self::VECTOR_V1, self::SECRET, now: self::T);
        $this->assertSame('webhook.ping', $event['event']);
        $this->assertSame(['message' => 'hi'], $event['data']);
        $this->assertSame(['id' => '1', 'type' => 'bot', 'name' => 'Bot', 'url' => 'https://thediscordlist.com/bots/1'], $event['listing']);
    }

    public function test_valid_signature_with_default_clock(): void
    {
        $now = time();
        $event = Verifier::verify(self::BODY, $this->header(self::BODY, $now), self::SECRET);

        $this->assertSame('d1', $event['id']);
    }

    public function test_uppercase_hex_signature_verifies(): void
    {
        $header = 't='.self::T.',v1='.strtoupper(self::VECTOR_V1);

        $this->assertSame('d1', Verifier::verify(self::BODY, $header, self::SECRET, now: self::T)['id']);
    }

    public function test_tampered_body_is_rejected(): void
    {
        $tampered = str_replace('"hi"', '"bye"', self::BODY);

        $this->assertRejected(ErrorCode::InvalidSignature, $tampered, 't='.self::T.',v1='.self::VECTOR_V1);
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $this->assertThrowsCode(ErrorCode::InvalidSignature, fn () => Verifier::verify(self::BODY, 't='.self::T.',v1='.self::VECTOR_V1, 'whsec_other', now: self::T));
    }

    public function test_stale_timestamp_in_the_past(): void
    {
        $this->assertThrowsCode(ErrorCode::StaleTimestamp, fn () => Verifier::verify(self::BODY, $this->header(self::BODY, self::T), self::SECRET, now: self::T + 301));
    }

    public function test_stale_timestamp_in_the_future(): void
    {
        $this->assertThrowsCode(ErrorCode::StaleTimestamp, fn () => Verifier::verify(self::BODY, $this->header(self::BODY, self::T), self::SECRET, now: self::T - 301));
    }

    public function test_tolerance_edges_are_inclusive_and_configurable(): void
    {
        $header = $this->header(self::BODY, self::T);

        $this->assertSame('d1', Verifier::verify(self::BODY, $header, self::SECRET, now: self::T + 300)['id']);
        $this->assertSame('d1', Verifier::verify(self::BODY, $header, self::SECRET, now: self::T - 300)['id']);
        $this->assertSame('d1', Verifier::verify(self::BODY, $header, self::SECRET, 3600, self::T + 3000)['id']);
    }

    public function test_missing_header(): void
    {
        $this->assertRejected(ErrorCode::MissingSignature, self::BODY, null);
        $this->assertRejected(ErrorCode::MissingSignature, self::BODY, '  ');
    }

    /** @return iterable<string, array{string}> */
    public static function malformedHeaders(): iterable
    {
        yield 'no parts' => ['garbage'];
        yield 'no t' => ['v1='.self::VECTOR_V1];
        yield 'no v1' => ['t='.self::T];
        yield 'empty v1 only' => ['t='.self::T.',v1='];
        yield 'non-numeric t' => ['t=abc,v1='.self::VECTOR_V1];
        yield 'non-finite t' => ['t=1e999,v1='.self::VECTOR_V1];
    }

    #[DataProvider('malformedHeaders')]
    public function test_malformed_header(string $header): void
    {
        $this->assertRejected(ErrorCode::MalformedSignature, self::BODY, $header);
    }

    public function test_parts_split_on_the_first_equals_only(): void
    {
        $header = ' t = '.self::T.' , extra=a=b==, v1='.self::VECTOR_V1.' ,v1=ignored';

        $this->assertSame('d1', Verifier::verify(self::BODY, $header, self::SECRET, now: self::T)['id']);
    }

    public function test_value_with_extra_equals_is_a_bad_signature_not_a_malformed_header(): void
    {
        $this->assertRejected(ErrorCode::InvalidSignature, self::BODY, 't='.self::T.',v1='.self::VECTOR_V1.'=');
    }

    public function test_first_non_empty_v1_is_used(): void
    {
        $header = 't='.self::T.',v1=,v1='.self::VECTOR_V1;

        $this->assertSame('d1', Verifier::verify(self::BODY, $header, self::SECRET, now: self::T)['id']);
    }

    public function test_first_t_wins(): void
    {
        $this->assertRejected(ErrorCode::StaleTimestamp, self::BODY, 't=1,t='.self::T.',v1='.self::VECTOR_V1);
    }

    public function test_signed_non_json_body_is_malformed_body(): void
    {
        $this->assertRejected(ErrorCode::MalformedBody, 'not json', $this->header('not json', self::T));
        $this->assertRejected(ErrorCode::MalformedBody, '"a string"', $this->header('"a string"', self::T));
    }

    public function test_unknown_event_names_parse(): void
    {
        $body = '{"id":"d2","event":"listing.renamed","created_at":"2026-01-01T00:00:00+00:00","listing":{"id":"1","type":"bot","name":"B","url":"https://thediscordlist.com/bots/1"},"data":{"x":1}}';

        $this->assertSame('listing.renamed', Verifier::verify($body, $this->header($body, self::T), self::SECRET, now: self::T)['event']);
    }

    public function test_reencoded_body_never_verifies(): void
    {
        $decoded = json_decode(self::BODY, true);

        $this->assertRejected(ErrorCode::InvalidSignature, (string) json_encode($decoded), 't='.self::T.',v1='.self::VECTOR_V1);
    }

    private function header(string $body, int $t): string
    {
        return "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", self::SECRET);
    }

    private function assertRejected(ErrorCode $expected, string $body, ?string $header): void
    {
        $this->assertThrowsCode($expected, fn () => Verifier::verify($body, $header, self::SECRET, now: self::T));
    }

    private function assertThrowsCode(ErrorCode $expected, callable $verify): void
    {
        try {
            $verify();
            $this->fail("Expected {$expected->value}.");
        } catch (VerificationException $error) {
            $this->assertSame($expected, $error->reason);
            $this->assertNotSame('', $error->getMessage());
        }
    }
}
