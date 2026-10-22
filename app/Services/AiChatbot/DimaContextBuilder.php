<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

/**
 * Recent dialogue plus structured memory. The latest user message is marked so it outranks older goals.
 */
class DimaContextBuilder
{
    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     * @param  array<string, mixed>  $prior
     */
    public function build(array $turns, ?string $name, array $prior): string
    {
        $clean = trim((string) $name);
        $female = $clean !== '' && MansourPrompt::isFemaleName($clean);
        $memory = DimaPrompt::memoryFrom($prior['memory'] ?? null);
        $recent = array_slice($this->filled($turns), -10);
        $latest = $this->latestUser($turns);

        $lines = [
            $clean === '' ? 'الاسم غير معروف. خاطبيه بصيغة المذكر ومن دون اسم.' : 'الاسم: '.$clean,
            'الصيغة: '.($female ? 'مؤنث' : 'مذكر'),
            'الموضوع السابق: '.($memory['topic'] !== '' ? $memory['topic'] : 'غير محدد'),
            'اللي قاله هو: '.$memory['known'],
            'أسئلة انجاوبت: '.$memory['answered'],
            'سؤال لسا مفتوح: '.$memory['open_question'],
            'إذا آخر رسالة شرحت الموقف، لا تفتحي سؤال جديد لتسكير سؤال قديم.',
            'اعتراضه: '.$memory['concern'],
            'المحادثة مغلقة سابقا: '.(((bool) ($prior['close'] ?? false)) ? 'نعم، وإذا رجع يحكي كملي' : 'لا'),
            'آخر رسالة من الشخص، وهي الأهم:',
            $latest !== '' ? $latest : 'ما في رسالة.',
            'الرسائل الأخيرة، الأقدم أولا:',
        ];
        if ($recent === []) {
            $lines[] = 'ما في رسائل سابقة.';
        }
        foreach ($recent as $turn) {
            $who = ($turn['role'] ?? '') === 'assistant' ? 'ديما' : 'الشخص';
            $lines[] = $who.': '.trim((string) ($turn['message'] ?? ''));
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    public function latestUser(array $turns): string
    {
        $last = '';
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') !== 'assistant' && trim((string) ($turn['message'] ?? '')) !== '') {
                $last = trim((string) $turn['message']);
            }
        }

        return $last;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     * @return list<array{role?:string,message?:string}>
     */
    private function filled(array $turns): array
    {
        $kept = [];
        foreach ($turns as $turn) {
            if (trim((string) ($turn['message'] ?? '')) !== '') {
                $kept[] = $turn;
            }
        }

        return $kept;
    }
}
