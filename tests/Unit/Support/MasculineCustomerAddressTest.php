<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Jobs\ProcessCampaignConversationJob;
use App\Support\MasculineCustomerAddress;
use Tests\TestCase;

class MasculineCustomerAddressTest extends TestCase
{
    public function test_rewrites_feminine_second_person_to_masculine(): void
    {
        $mixed = 'تمام، إذا تحبي تكملي، بقدر أخلي الصبيه تتواصل معك كمان شوي تشرحلك أكثر. بدك أخليها تتواصل معك عهاذ الرقم؟';

        $this->assertSame(
            'تمام، إذا تحب تكمل، بقدر أخلي الصبيه تتواصل معك كمان شوي تشرحلك أكثر. بدك أخليها تتواصل معك عهاذ الرقم؟',
            MasculineCustomerAddress::sanitize($mixed),
        );
    }

    public function test_canned_same_number_ask_stays_masculine(): void
    {
        $ask = ProcessCampaignConversationJob::SAME_NUMBER_ASK_REPLY;

        $this->assertSame($ask, MasculineCustomerAddress::sanitize($ask));
        $this->assertStringContainsString('حابب', $ask);
        $this->assertStringNotContainsString('تحبي', $ask);
    }

    public function test_does_not_feminize_the_girl_or_sally(): void
    {
        $ok = 'بقدر اخلي الصبيه تتواصل معك كمان شوي تشرحلك اكثر.';

        $this->assertSame($ok, MasculineCustomerAddress::sanitize($ok));
    }
}
