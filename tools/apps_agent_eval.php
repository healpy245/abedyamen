<?php

declare(strict_types=1);

/**
 * Optional live check of the apps agent. Not part of the test suite.
 *
 * php tools/apps_agent_eval.php --live
 */

if (! in_array('--live', $argv, true)) {
    fwrite(STDOUT, "Skipped. Pass --live to call the configured model.\n");
    exit(0);
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\AiChatbot\AppsPrompt;

$links = [
    'aswatkum' => AppsPrompt::ASWATKUM_ANDROID_URL,
    'muwahada' => AppsPrompt::MUWAHADA_URL,
    'video' => AppsPrompt::GUIDE_VIDEO_URL,
];
$opening = ['role' => 'assistant', 'message' => AppsPrompt::DEFAULT_OPENING];

$scenarios = [
    'urgency' => [$opening, ['role' => 'user', 'message' => 'ضروري هسه؟']],
    'login-and-list' => [
        ['role' => 'assistant', 'message' => 'في عندك حساب داخل التطبيق ولا كيف؟'],
        ['role' => 'user', 'message' => 'تمام سجلت دخولي بس مش عارف وين اللائحة'],
    ],
    'hebrew-yes' => [
        ['role' => 'assistant', 'message' => 'في عندك حساب داخل التطبيق ولا كيف؟'],
        ['role' => 'user', 'message' => 'נכון'],
    ],
    'credentials' => [$opening, ['role' => 'user', 'message' => 'منين اليوزر والسيسما؟']],
    'continue' => [
        $opening,
        ['role' => 'user', 'message' => 'نزلت التطبيق وعطيت الصلاحية'],
        ['role' => 'assistant', 'message' => 'تمام. في عندك حساب داخل التطبيق ولا كيف؟'],
        ['role' => 'user', 'message' => 'كمل'],
    ],
    'off-topic' => [$opening, ['role' => 'user', 'message' => 'قديش قطر الأرض؟']],
];

foreach ($scenarios as $name => $turns) {
    $reply = AppsPrompt::nextReply($turns, 'فتحي', $links);
    fwrite(STDOUT, "\n## {$name}\n{$reply['text']}\n");
    fwrite(STDOUT, 'pause='.($reply['pause'] ? 'yes' : 'no')."\n");
}
