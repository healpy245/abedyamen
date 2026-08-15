<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Services\Voice\Streaming\VoiceResponseChunker;
use PHPUnit\Framework\TestCase;

class VoiceResponseChunkerTest extends TestCase
{
    public function test_splits_arabic_sentences_for_fast_first_chunk(): void
    {
        $chunker = new VoiceResponseChunker;
        $chunks = $chunker->split('أه تمام، فحصتلك الحساب. ظاهر عندي إن الخط مفصول بسبب دين.');

        $this->assertNotEmpty($chunks);
        $this->assertLessThanOrEqual(70, mb_strlen($chunks[0]));
        $this->assertStringContainsString('تمام', $chunks[0]);
    }

    public function test_feed_flushes_on_sentence_end(): void
    {
        $chunker = new VoiceResponseChunker;
        $buf = '';
        $out = null;
        foreach (preg_split('//u', 'أه تمام، فحصتلك الحساب. باقي الكلام', -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $fed = $chunker->feed($buf, $ch, true);
            $buf = $fed['buffer'];
            if ($fed['phrase']) {
                $out = $fed['phrase'];
                break;
            }
        }

        $this->assertNotNull($out);
        $this->assertStringContainsString('حساب', $out);
    }
}
