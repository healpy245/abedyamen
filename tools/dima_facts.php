<?php

declare(strict_types=1);

use App\Services\AiChatbot\DimaPrompt;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$base = [
    ['role' => 'assistant', 'message' => 'يسعد اوقاتك اخ مازن شو الاخبار'],
    ['role' => 'user', 'message' => 'هلا'],
    ['role' => 'assistant', 'message' => "بعرف إنه تواصلي معك إجا فجأة، بس أنا من مركز استطلاعات وبنحاول نسمع من الناس عن الأوضاع بمجتمعنا العربي.\n\nبتسمحلي أسألك سؤال صغير؟"],
    ['role' => 'user', 'message' => 'احكي'],
    ['role' => 'assistant', 'message' => 'تمام، ناوي تطلع تصوت هالمرة؟'],
];

$spots = [
    'فتات' => array_merge($base, [
        ['role' => 'user', 'message' => 'بصوت بس كلهم فتات، وين الـ30 مليار؟'],
    ]),
    'لم الشمل' => array_merge($base, [
        ['role' => 'user', 'message' => 'ليش امتنعتوا عن قانون لم الشمل؟'],
    ]),
];

foreach ($spots as $title => $turns) {
    $decision = DimaPrompt::nextReply($turns, 'مازن', ['stance' => 'open', 'note' => '', 'close' => false]);
    echo "===== {$title} =====\n";
    echo $decision['text']."\n\n";
}
