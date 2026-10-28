<?php

namespace Tests\Unit;

use App\Services\Webhooks\SignatureVerifier;
use PHPUnit\Framework\TestCase;

class SignatureVerifierTest extends TestCase
{
    private SignatureVerifier $v;

    protected function setUp(): void
    {
        parent::setUp();
        $this->v = new SignatureVerifier;
    }

    public function test_a_fresh_correct_signature_verifies(): void
    {
        $header = $this->v->sign('s3cret', '{"a":1}', 1_700_000_000);

        $this->assertSame('ok', $this->v->verify('s3cret', '{"a":1}', $header, 1_700_000_010, 300));
    }

    public function test_the_header_has_the_documented_format(): void
    {
        $this->assertMatchesRegularExpression('/^t=1700000000,v1=[a-f0-9]{64}$/', $this->v->sign('k', 'x', 1_700_000_000));
    }

    public function test_the_signature_is_hmac_sha256_over_timestamp_dot_body(): void
    {
        $expected = hash_hmac('sha256', '1700000000.{"a":1}', 'k');

        $this->assertSame("t=1700000000,v1={$expected}", $this->v->sign('k', '{"a":1}', 1_700_000_000));
    }

    public function test_a_changed_body_is_rejected(): void
    {
        $header = $this->v->sign('k', '{"a":1}', 1_700_000_000);

        $this->assertSame('mismatch', $this->v->verify('k', '{"a":2}', $header, 1_700_000_001, 300));
    }

    public function test_a_wrong_secret_is_rejected(): void
    {
        $header = $this->v->sign('k', 'body', 1_700_000_000);

        $this->assertSame('mismatch', $this->v->verify('other', 'body', $header, 1_700_000_001, 300));
    }

    public function test_old_and_future_timestamps_are_rejected_as_replays(): void
    {
        $header = $this->v->sign('k', 'body', 1_700_000_000);

        $this->assertSame('expired', $this->v->verify('k', 'body', $header, 1_700_000_301, 300));
        $this->assertSame('expired', $this->v->verify('k', 'body', $header, 1_699_999_600, 300));
        $this->assertSame('ok', $this->v->verify('k', 'body', $header, 1_700_000_300, 300));
    }

    public function test_the_timestamp_cannot_be_swapped_without_breaking_the_signature(): void
    {
        $header = $this->v->sign('k', 'body', 1_700_000_000);
        $forged = preg_replace('/^t=\d+/', 't=1700000500', $header);

        $this->assertSame('mismatch', $this->v->verify('k', 'body', $forged, 1_700_000_500, 300));
    }

    public function test_missing_and_malformed_headers_are_reported_separately(): void
    {
        $this->assertSame('missing', $this->v->verify('k', 'b', null, 1, 300));
        $this->assertSame('missing', $this->v->verify('k', 'b', '  ', 1, 300));
        $this->assertSame('malformed', $this->v->verify('k', 'b', 'garbage', 1, 300));
        $this->assertSame('malformed', $this->v->verify('k', 'b', 't=abc,v1=ff', 1, 300));
        $this->assertSame('malformed', $this->v->verify('k', 'b', 't=1', 1, 300));
    }

    public function test_any_matching_v1_value_is_accepted_to_allow_secret_rotation(): void
    {
        $good = explode('v1=', $this->v->sign('new', 'b', 1_700_000_000))[1];
        $header = "t=1700000000,v1=".str_repeat('0', 64).",v1={$good}";

        $this->assertSame('ok', $this->v->verify('new', 'b', $header, 1_700_000_001, 300));
    }
}
