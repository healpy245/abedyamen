<?php

declare(strict_types=1);

namespace App\Services\AiChatbot\DimaV3;

use App\Services\AiChatbot\DimaFacts;

/**
 * The whole fact sheet goes into every prompt. It is small enough, and it
 * stays byte-identical between turns so the provider can cache it. No
 * keyword retrieval: a missed keyword used to leave the model without facts.
 */
class KnowledgeBase
{
    public function render(): string
    {
        $lines = ['## وقائع بمصدر (سجل رسمي أو مصدر مستقل)'];
        foreach (array_merge(DimaFacts::entries(), DimaFacts::partyEntries()) as $entry) {
            $stale = DimaFacts::isStale($entry['last_verified_at']) ? ' (قديم، لا تعرضيه كوضع اليوم)' : '';
            $lines[] = '- ['.$entry['topic'].' | '.$entry['source_name'].' | '.$entry['publication_date'].$stale.'] '.$entry['claim'];
        }
        $lines[] = '';
        $lines[] = '## موقف القائمة (دليل الكوادر 2026، وثيقة القائمة نفسها: احكيه كموقف الموحدة، وهو المرجع الأول لأجوبتك)';
        foreach (CadreGuide::sections() as $section) {
            $lines[] = '- ['.$section['title'].'] '.$section['body'];
        }

        return implode("\n", $lines);
    }
}
