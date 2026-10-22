<?php

declare(strict_types=1);

/**
 * Dynamic live check. The next user line is sent only after the real model reply.
 *
 * php tools/apps_agent_dynamic.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\AiChatbot\AppsPrompt;

$key = trim((string) (config('services.openai.api_key') ?: env('OPENAI_API_KEY')));
if ($key === '') {
    fwrite(STDERR, "No OpenAI key configured.\n");
    exit(1);
}

$links = [
    'aswatkum' => AppsPrompt::ASWATKUM_ANDROID_URL,
    'muwahada' => AppsPrompt::MUWAHADA_URL,
    'video' => AppsPrompt::GUIDE_VIDEO_URL,
];
$failed = 0;

/**
 * @param  list<array{role:string,message:string}>  $turns
 * @param  array{facts:list<string>,open:list<string>,links_sent:list<string>}  $memory
 * @param  list<array{0:string,1:Closure(array):bool}>  $checks
 * @return array{text:string,pause:bool,memory:array{facts:list<string>,open:list<string>,links_sent:list<string>}}
 */
function say(string $title, array &$turns, string $user, array &$memory, array $links, array $checks, int &$failed): array
{
    $turns[] = ['role' => 'user', 'message' => $user];
    fwrite(STDOUT, "\n[$title] user: {$user}\n");
    $reply = AppsPrompt::nextReply($turns, 'فتحي', $links, $memory);
    $turns[] = ['role' => 'assistant', 'message' => $reply['text']];
    $memory = $reply['memory'];
    fwrite(STDOUT, "bot: {$reply['text']}\n");
    fwrite(STDOUT, 'pause='.($reply['pause'] ? 'yes' : 'no').' facts='.json_encode($memory['facts'], JSON_UNESCAPED_UNICODE)."\n");
    foreach ($checks as [$label, $check]) {
        $ok = $check($reply);
        fwrite(STDOUT, ($ok ? '  ok  ' : '  FAIL ').$label."\n");
        if (! $ok) {
            $failed++;
        }
    }

    return $reply;
}

$has = static fn (string $needle): Closure => static fn (array $reply): bool => str_contains($reply['text'], $needle);
$lacks = static fn (string $needle): Closure => static fn (array $reply): bool => ! str_contains($reply['text'], $needle);
$matches = static fn (string $pattern): Closure => static fn (array $reply): bool => preg_match($pattern, $reply['text']) === 1;
$open = static fn (array $reply): bool => $reply['pause'] === false;

$opening = ['role' => 'assistant', 'message' => AppsPrompt::DEFAULT_OPENING];

fwrite(STDOUT, "\n======== download, then account, then details ========\n");
$turns = [$opening];
$memory = ['facts' => [], 'open' => [], 'links_sent' => []];

say('no-app', $turns, 'لا لسا ما نزلت', $memory, $links, [
    ['both store links', static fn (array $reply): bool => str_contains($reply['text'], AppsPrompt::ASWATKUM_APPLE_URL) && str_contains($reply['text'], 'aswatkom')],
    ['does not ask the phone type', $lacks('آيفون ولا أندرويد')],
    ['does not jump to الموحدة', $lacks('almowahada')],
    ['stays open', $open],
], $failed);

say('installed', $turns, 'تمام نزلته وعطيت صلاحية مشاركة الأرقام', $memory, $links, [
    ['asks about an account', $has('حساب')],
    ['does not ask if the app was downloaded again', $lacks('هل نزّلت')],
], $failed);

say('no-account', $turns, 'لا، ما معي حساب', $memory, $links, [
    ['asks for the name', $has('اسم')],
    ['asks for the town', static fn (array $reply): bool => str_contains($reply['text'], 'بلدة') || str_contains($reply['text'], 'بلد')],
    ['asks for the id', $has('هوية')],
    ['does not ask for a password', static fn (array $reply): bool => preg_match('/باسورد|كلمة السر|password/ui', $reply['text']) !== 1],
    ['does not claim the account was created', $lacks('تم تسجيلك')],
], $failed);

say('details', $turns, 'يامن عامر من كفر قاسم', $memory, $links, [
    ['asks for the id on WhatsApp', static fn (array $reply): bool => preg_match('/ابعت[^.\n]{0,80}هوية/u', $reply['text']) === 1 && preg_match('/ما بقدر|ما بنجمع|لحماية خصوصيتك|ما تبعت|لا تبعت/u', $reply['text']) !== 1],
    ['does not store a confirmation as a name', static fn (array $reply): bool => ! in_array('נכון', $reply['memory']['facts'], true) && ! in_array('نكون', $reply['memory']['facts'], true)],
    ['does not claim the account was created', $lacks('تم تسجيلك')],
], $failed);

say('id', $turns, '331726943', $memory, $links, [
    ['waits for confirmation', $has('تم')],
    ['does not claim the account was created', $lacks('تم تسجيلك')],
    ['does not keep the id in memory', static fn (array $reply): bool => ! str_contains(json_encode($reply['memory'], JSON_UNESCAPED_UNICODE) ?: '', '331726943')],
], $failed);

say('confirm', $turns, 'تم', $memory, $links, [
    ['does not claim an unverified registration', $lacks('تم تسجيلك')],
    ['does not send الموحدة before the dashboard registers him', $lacks('almowahada')],
], $failed);

fwrite(STDOUT, "\n======== mixed login and list ========\n");
$turns = [
    $opening,
    ['role' => 'user', 'message' => 'نزلت التطبيق'],
    ['role' => 'assistant', 'message' => 'تمام. افتح التطبيق واعطي الصلاحية. في عندك حساب داخل التطبيق ولا كيف؟'],
];
$memory = ['facts' => ['نزل أصواتكم'], 'open' => [], 'links_sent' => ['aswatkum']];
say('login-and-list', $turns, 'تمام سجلت دخولي بس مش عارف وين اللائحة', $memory, $links, [
    ['does not ask whether login happened', static fn (array $reply): bool => preg_match('/هل\s.*دخول|سجلت دخولك\؟|هل قمت/u', $reply['text']) !== 1],
    ['talks about the list', $has('لائحة')],
    ['does not restart the opening', $lacks('بحاجة لدقيقة')],
], $failed);

fwrite(STDOUT, "\n======== hebrew confirmation ========\n");
$turns = [['role' => 'assistant', 'message' => 'في عندك حساب داخل التطبيق ولا كيف؟']];
$memory = ['facts' => [], 'open' => [], 'links_sent' => []];
say('nakhon', $turns, 'נכון', $memory, $links, [
    ['does not treat it as a name', static fn (array $reply): bool => ! str_contains($reply['text'], 'نكون') && ! str_contains($reply['text'], 'נכון')],
    ['does not store it as a name', static fn (array $reply): bool => ! in_array('נכון', $reply['memory']['facts'], true) && ! in_array('نكون', $reply['memory']['facts'], true)],
], $failed);

fwrite(STDOUT, "\n======== credentials ========\n");
$turns = [$opening];
$memory = ['facts' => [], 'open' => [], 'links_sent' => []];
say('credentials', $turns, 'منين اليوزر والسيسما؟', $memory, $links, [
    ['does not ask for a password', static fn (array $reply): bool => preg_match('/باسورد|كلمة السر|password/ui', $reply['text']) !== 1],
    ['does not send them to a registration officer', $lacks('مسؤول التسجيل')],
], $failed);

fwrite(STDOUT, "\n======== urgency, then continue ========\n");
$turns = [$opening];
$memory = ['facts' => [], 'open' => [], 'links_sent' => []];
say('urgent', $turns, 'ضروري هسه؟', $memory, $links, [
    ['says it is needed now', $matches('/مطلوب|مهم|هلق|هلأ|لازم/u')],
    ['does not treat it as a refusal', $lacks('سأمرر رقمك')],
    ['does not say هسه', $lacks('هسه')],
], $failed);
say('continue', $turns, 'كمل', $memory, $links, [
    ['does not restart the introduction', $lacks('بحاجة لدقيقة من وقتك')],
], $failed);

fwrite(STDOUT, "\n======== off topic ========\n");
$turns = [$opening];
$memory = ['facts' => [], 'open' => [], 'links_sent' => []];
say('earth', $turns, 'قديش قطر الأرض؟', $memory, $links, [
    ['refuses the trivia', $has('نخرج عن سياق المحادثة')],
    ['does not answer with a measurement', static fn (array $reply): bool => preg_match('/\d{3,}/', $reply['text']) !== 1],
], $failed);

fwrite(STDOUT, "\n======== android means الموحدة once that app is the topic ========\n");
$turns = [
    $opening,
    ['role' => 'user', 'message' => 'خلصت أصواتكم واللائحة'],
    ['role' => 'assistant', 'message' => 'تمام. بقي تطبيق الموحدة.'],
];
$memory = ['facts' => ['خلص أصواتكم واللائحة'], 'open' => [], 'links_sent' => ['aswatkum']];
say('android', $turns, 'اندرويد', $memory, $links, [
    ['sends the الموحدة link', $has('almowahada')],
    ['does not invent a link', $lacks('com.fake')],
    ['does not say the link is missing', static fn (array $reply): bool => preg_match('/مش متوفر|غير متوفر/u', $reply['text']) !== 1],
], $failed);

fwrite(STDOUT, "\n======== interruption, then the account answer ========\n");
$turns = [['role' => 'assistant', 'message' => 'في عندك حساب داخل التطبيق ولا كيف؟']];
$memory = ['facts' => ['نزل أصواتكم'], 'open' => ['في عندك حساب؟'], 'links_sent' => ['aswatkum']];
say('interrupt', $turns, 'كم الساعة عندكم؟ وبعدين قلي، اه عندي حساب', $memory, $links, [
    ['does not ask the account question again', $lacks('في عندك حساب داخل التطبيق ولا كيف')],
    ['moves on', $matches('/لائحة|صلاحية|دخول/u')],
], $failed);

fwrite(STDOUT, "\n{$failed} failed check".($failed === 1 ? '' : 's')."\n");
exit($failed > 0 ? 1 : 0);
