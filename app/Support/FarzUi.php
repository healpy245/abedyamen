<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AiChatbot\MansourInsightService as G;
use Illuminate\Support\HtmlString;

/**
 * Shared look for the فرز (Mansour) screens: stroke icons on a 24×24 grid
 * and the label/colour/icon of each voter group.
 */
final class FarzUi
{
    /**
     * @return array<string, array{label: string, color: string, icon: string}>
     */
    public static function appGroups(): array
    {
        return [
            'downloaded' => ['label' => 'نزّلوا التطبيق', 'color' => '#22c55e', 'icon' => 'download'],
            'waiting_account' => ['label' => 'ينتظرون حساب', 'color' => '#f97316', 'icon' => 'user'],
            'no_reply' => ['label' => 'لم يرد', 'color' => '#eab308', 'icon' => 'hourglass'],
            'follow_up' => ['label' => 'ما بدهم يكملوا', 'color' => '#7c3aed', 'icon' => 'chat-x'],
        ];
    }

    /**
     * @return array<string, array{label: string, topic: string, hint: string, color: string, icon: string}>
     */
    public static function dimaGroups(): array
    {
        return [
            'united' => ['label' => 'الموحدة', 'topic' => 'مصوت للموحدة', 'hint' => 'مصوّت للقائمة العربية الموحدة.', 'color' => '#22c55e', 'icon' => 'ballot'],
            'joint' => ['label' => 'المشتركة', 'topic' => 'مصوت للمشتركة', 'hint' => 'مصوّت للقائمة المشتركة.', 'color' => '#14b8a6', 'icon' => 'question'],
            'religious' => ['label' => 'مقاطع ديني', 'topic' => 'مقاطع ديني', 'hint' => 'رافض للتصويت لسبب ديني.', 'color' => '#64748b', 'icon' => 'user-x'],
            'identity' => ['label' => 'مقاطع هوية', 'topic' => 'مقاطع هوية', 'hint' => 'رافض لأنه شايف التصويت تنازل.', 'color' => '#0ea5e9', 'icon' => 'flag'],
            'no_gain' => ['label' => 'بلا إنجاز', 'topic' => 'ما في إنجازات', 'hint' => 'شايف إن الانتخابات ما بتفرق.', 'color' => '#a16207', 'icon' => 'hand'],
            'zionist' => ['label' => 'حزب صهيوني', 'topic' => 'مصوت لحزب صهيوني', 'hint' => 'مصوّت لحزب صهيوني.', 'color' => '#f43f5e', 'icon' => 'thumbs-down'],
            'open' => ['label' => 'بالحديث', 'topic' => 'لسه بالحديث', 'hint' => 'رد وما توضّح موقفه بعد.', 'color' => '#8b5cf6', 'icon' => 'chat'],
            'refused' => ['label' => 'رفض الحديث', 'topic' => 'رفض يكمل الحديث', 'hint' => 'طلب يوقف أو رفض يكمل.', 'color' => '#f97316', 'icon' => 'chat-x'],
            'no_reply' => ['label' => 'لم يرد', 'topic' => 'لم يرد', 'hint' => 'انبعت الرسالة وما في رد.', 'color' => '#eab308', 'icon' => 'hourglass'],
        ];
    }

    /**
     * @return array<string, array{label: string, topic: string, hint: string, color: string, icon: string}>
     */
    public static function inviteGroups(): array
    {
        return [
            'positive' => ['label' => 'رد إيجابي', 'topic' => 'رد إيجابي', 'hint' => 'أكدت حضورها.', 'color' => '#22c55e', 'icon' => 'check'],
            'cancel' => ['label' => 'إلغاء', 'topic' => 'إلغاء', 'hint' => 'طلبت إلغاء الاشتراك أو رفضت الحضور.', 'color' => '#f43f5e', 'icon' => 'chat-x'],
            'no_reply' => ['label' => 'لم ترد', 'topic' => 'لم ترد', 'hint' => 'انبعتت الدعوة وما في رد.', 'color' => '#eab308', 'icon' => 'hourglass'],
        ];
    }

    public static function groups(): array
    {
        return [
            G::GROUP_CERTAIN => ['label' => 'الموحدة', 'color' => '#22c55e', 'icon' => 'ballot'],
            G::GROUP_UNSURE => ['label' => 'المشتركة', 'color' => '#14b8a6', 'icon' => 'question'],
            G::GROUP_NOT_VOTING => ['label' => 'مقاطع', 'color' => '#94a3b8', 'icon' => 'user-x'],
            G::GROUP_OTHER_SIDE => ['label' => 'حزب صهيوني', 'color' => '#f43f5e', 'icon' => 'thumbs-down'],
            G::GROUP_REFUSED => ['label' => 'رفضوا الحديث', 'color' => '#f97316', 'icon' => 'chat-x'],
            G::GROUP_NO_REPLY => ['label' => 'لم يرد', 'color' => '#eab308', 'icon' => 'hourglass'],
        ];
    }

    private const PATHS = [
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18 14.5a6.5 6.5 0 0 1 3.5 5.5"/>',
        'send' => '<path d="M21 3 10 14"/><path d="M21 3l-7 18-4-7-7-4z"/>',
        'chat' => '<path d="M5 5h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H9l-4 3.5V17H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/><path d="M8 10h8M8 13h5"/>',
        'percent' => '<path d="M19 5 5 19"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/>',
        'check' => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.8 2.8L16.5 9"/>',
        'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'ban' => '<circle cx="12" cy="12" r="9"/><path d="M5.7 5.7l12.6 12.6"/>',
        'flag' => '<path d="M5 21V4"/><path d="M5 4h11l-2 4 2 4H5"/>',
        'hand' => '<path d="M8 13V5.5a1.5 1.5 0 0 1 3 0V11"/><path d="M11 10V4.5a1.5 1.5 0 0 1 3 0V11"/><path d="M14 10.5V6a1.5 1.5 0 0 1 3 0v8a7 7 0 0 1-7 7h-.5A6.5 6.5 0 0 1 4 15.5l-.9-2.6a1.5 1.5 0 0 1 2.7-1.2L8 15"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'bot' => '<rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 4v4"/><circle cx="12" cy="3.5" r="1"/><circle cx="9" cy="14" r="1.2"/><circle cx="15" cy="14" r="1.2"/>',
        'power' => '<path d="M12 3v8"/><path d="M6.3 7.3a8 8 0 1 0 11.4 0"/>',
        'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
        'download' => '<path d="M12 4v11"/><path d="M7 11l5 5 5-5"/><path d="M5 20h14"/>',
        'flask' => '<path d="M9 3h6"/><path d="M10 3v6l-5.5 9.5A1.7 1.7 0 0 0 6 21h12a1.7 1.7 0 0 0 1.5-2.5L14 9V3"/><path d="M7.5 15h9"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'alert' => '<path d="M12 3l10 18H2z"/><path d="M12 10v4"/><path d="M12 17.5h.01"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'pie' => '<path d="M21 12A9 9 0 1 1 12 3v9z"/><path d="M15 3.5A9 9 0 0 1 20.5 9H15z"/>',
        'chart' => '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-5M12 16V8M16 16v-3"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'inbox' => '<path d="M3 13h5l1.5 3h5L16 13h5"/><path d="M5 5h14l2 8v6H3v-6z"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
        'logout' => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 17l-5-5 5-5"/><path d="M5 12h11"/>',
        'arrow' => '<path d="M14 6l-6 6 6 6"/>',
        'back' => '<path d="M10 6l6 6-6 6"/>',
        'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
        'refresh' => '<path d="M20 11a8 8 0 0 0-14.6-4.5L4 8"/><path d="M4 4v4h4"/><path d="M4 13a8 8 0 0 0 14.6 4.5L20 16"/><path d="M20 20v-4h-4"/>',
        'paperclip' => '<path d="M21 11.5 12.5 20a5 5 0 0 1-7-7L14 4.5a3.5 3.5 0 0 1 5 5L10.5 18a2 2 0 0 1-3-3l8-8"/>',
        'mic' => '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0"/><path d="M12 18v3"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'save' => '<path d="M5 4h11l4 4v12H5z"/><path d="M8 4v5h7V4"/><path d="M8 20v-6h8v6"/>',
        'copy' => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
        'webhook' => '<circle cx="6" cy="17" r="2.5"/><circle cx="18" cy="17" r="2.5"/><circle cx="12" cy="6" r="2.5"/><path d="M8.5 17h7"/><path d="M10.8 8.2 7 14.8"/><path d="M13.2 8.2 17 14.8"/>',
        'message' => '<path d="M4 5h16v11H8l-4 4z"/>',
        'upload' => '<path d="M12 16V4"/><path d="M7 9l5-5 5 5"/><path d="M5 20h14"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'trash' => '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/>',
        'ballot' => '<path d="M3 12h18v8a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/><path d="M7 12V4a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v8"/><path d="M9.5 7.5l2 2 3.5-3.5"/><path d="M8 16.5h8"/>',
        'question' => '<circle cx="12" cy="12" r="9"/><path d="M9.3 9.2a2.8 2.8 0 0 1 5.4 1c0 1.9-2.7 2.4-2.7 4"/><path d="M12 17.5h.01"/>',
        'user-x' => '<circle cx="10" cy="8" r="4"/><path d="M3 21a7 7 0 0 1 11.5-5.4"/><path d="M17 15l4 4M21 15l-4 4"/>',
        'thumbs-down' => '<path d="M7 14V3"/><path d="M7 14H4.5A1.5 1.5 0 0 1 3 12.5v-8A1.5 1.5 0 0 1 4.5 3H7"/><path d="M7 14l4 7a2.5 2.5 0 0 0 2.5-2.5V15h5.2a2 2 0 0 0 2-2.3l-1.2-7.5A2.5 2.5 0 0 0 17 3H7"/>',
        'chat-x' => '<path d="M4 5h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H9l-5 4V6a1 1 0 0 1 1-1z"/><path d="M9.5 8.5l5 5M14.5 8.5l-5 5"/>',
        'hourglass' => '<path d="M5 22h14M5 2h14"/><path d="M17 22v-4.2a2 2 0 0 0-.6-1.4L12 12l-4.4 4.4a2 2 0 0 0-.6 1.4V22"/><path d="M7 2v4.2a2 2 0 0 0 .6 1.4L12 12l4.4-4.4a2 2 0 0 0 .6-1.4V2"/>',
        'signal' => '<path d="M5 12.5a10 10 0 0 1 14 0"/><path d="M8.5 16a5 5 0 0 1 7 0"/><path d="M12 19.5h.01"/><path d="M1.5 9a15 15 0 0 1 21 0"/>',
        'shield-x' => '<path d="M12 3l8 3v6c0 4.5-3.4 8.2-8 9-4.6-.8-8-4.5-8-9V6z"/><path d="M9.5 9.5l5 5M14.5 9.5l-5 5"/>',
        'checks' => '<path d="M3 12l4 4 8-9"/><path d="M11 16l1 1 9-10"/>',
        'lock' => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'spark' => '<path d="M12 3l1.6 5.2L19 10l-5.4 1.8L12 17l-1.6-5.2L5 10l5.4-1.8z"/><path d="M18 15l.6 1.8L20.5 18l-1.9.6L18 20.5l-.6-1.9L15.5 18l1.9-.6z"/>',
    ];

    public static function svg(string $name, string $class = ''): HtmlString
    {
        $class = trim('farz-i '.$class);

        return new HtmlString('<svg viewBox="0 0 24 24" class="'.e($class).'" aria-hidden="true">'.(self::PATHS[$name] ?? '').'</svg>');
    }
}
