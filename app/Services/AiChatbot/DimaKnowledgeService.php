<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

/**
 * Facts for the current message only. Older topics stay out unless the new message brings them back.
 */
class DimaKnowledgeService
{
    public function forMessage(string $latest, string $topic): string
    {
        $focus = trim($latest);
        if ($focus === '') {
            return 'ما في سؤال. لا تجيبي رقم أو تاريخ من عندك.';
        }

        $packet = DimaKnowledge::retrieve($focus, $topic);
        if ($packet === '') {
            return 'ما في وقائع لهالرسالة. إذا الجواب مش عندك، قولي إنك ما بتعرفي. لا تخترعي.';
        }

        return "وقائع لهالرسالة فقط. استخدميها إذا السؤال عنها. إذا السؤال عن رأيك أو عن جملة قلتيها، لا تفتحي موضوع جديد من الوقائع.\n"
            ."كراس القائمة وثيقة حزبية. السجل المنشور غير تفسيرك. انسبي كل رقم لمصدره، ولا تضيفي رقم مش مكتوب.\n"
            .$packet;
    }
}
