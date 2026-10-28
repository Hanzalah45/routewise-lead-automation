<?php

namespace Tests\Unit;

use App\Services\Leads\LeadNormalizer;
use Tests\TestCase;

class LeadNormalizerTest extends TestCase
{
    private function n(array $in): array
    {
        return (new LeadNormalizer)->normalise($in);
    }

    public function test_names_are_split_and_shouting_is_fixed_but_mixed_case_is_kept(): void
    {
        $a = $this->n(['name' => 'tariq mahmood khan']);
        $this->assertSame(['Tariq', 'Mahmood Khan'], [$a['first_name'], $a['last_name']]);

        $b = $this->n(['first_name' => 'ANNA', 'last_name' => 'McDonald']);
        $this->assertSame(['Anna', 'McDonald'], [$b['first_name'], $b['last_name']]);

        $this->assertSame('Tariq', $this->n(['full_name' => 'tariq x'])['first_name']);
    }

    public function test_email_is_lowercased_and_validated(): void
    {
        $this->assertSame('a@b.example', $this->n(['email' => '  A@B.Example '])['email']);
        $this->assertNull($this->n(['email' => 'not-an-email'])['email']);
        $this->assertNull($this->n(['email' => ['x']])['email']);
    }

    public function test_canonical_email_strips_plus_tags_and_gmail_dots(): void
    {
        $n = new LeadNormalizer;
        $this->assertSame('linda.park@corp.example', $n->canonicalEmail('Linda.Park+shop@corp.example'));
        $this->assertSame('lindapark@gmail.com', $n->canonicalEmail('Linda.Park+x@GoogleMail.com'));
    }

    public function test_free_disposable_and_company_domains_are_detected(): void
    {
        $free = $this->n(['email' => 'x@gmail.com']);
        $this->assertTrue($free['is_free_email']);
        $this->assertNull($free['company_domain']);

        $disposable = $this->n(['email' => 'x@mailinator.com']);
        $this->assertTrue($disposable['is_disposable_email']);
        $this->assertNull($disposable['company_domain']);

        $corp = $this->n(['email' => 'x@acme-corp.example']);
        $this->assertSame('acme-corp.example', $corp['company_domain']);
        $this->assertFalse($corp['is_free_email']);
    }

    public function test_phone_numbers_become_e164_only_when_they_can_be_trusted(): void
    {
        $n = new LeadNormalizer;
        $this->assertSame('+4930555011870', $n->phoneE164('+49 30 5550 11870', 'DE'));
        $this->assertSame('+14155550142', $n->phoneE164('(415) 555-0142', 'US'));
        $this->assertSame('+923005550198', $n->phoneE164('0300 5550198', 'PK'));
        $this->assertSame('+442079460958', $n->phoneE164('0044 20 7946 0958', null));
        $this->assertNull($n->phoneE164('555-0142', 'ZZ'), 'A national number with an unknown country is not guessed');
        $this->assertNull($n->phoneE164('12', 'US'));
        $this->assertNull($n->phoneE164('', 'US'));
        $this->assertNull($n->phoneE164(['x'], 'US'));
    }

    public function test_country_accepts_codes_and_common_names(): void
    {
        $this->assertSame('GB', $this->n(['country' => 'gb'])['country']);
        $this->assertSame('GB', $this->n(['country' => 'United Kingdom'])['country']);
        $this->assertNull($this->n(['country' => 'Atlantis'])['country']);
    }

    public function test_consent_and_honeypot(): void
    {
        $this->assertTrue($this->n(['consent' => 'yes'])['consent']);
        $this->assertFalse($this->n(['consent' => 'false'])['consent']);
        $this->assertNull($this->n([])['consent']);
        $this->assertTrue($this->n(['website' => 'http://spam.example'])['honeypot']);
        $this->assertFalse($this->n(['website' => ''])['honeypot']);
    }

    public function test_text_is_trimmed_collapsed_and_capped(): void
    {
        $n = $this->n(['company' => "  Acme \n  Corp  ", 'message' => str_repeat('a', 6000)]);

        $this->assertSame('Acme Corp', $n['company']);
        $this->assertSame(5000, mb_strlen($n['message']));
    }
}
