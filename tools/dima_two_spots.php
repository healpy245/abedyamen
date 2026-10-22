<?php

declare(strict_types=1);

use App\Services\AiChatbot\DimaPrompt;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$spots = [
    'مين بدك يصوت' => [
        ['role' => 'assistant', 'message' => 'يسعد اوقاتك اخ خالد شو الاخبار'],
        ['role' => 'user', 'message' => 'هلا'],
        ['role' => 'assistant', 'message' => "بعرف إنه تواصلي معك إجا فجأة، بس أنا من مركز استطلاعات وبنحاول نسمع من الناس عن الأوضاع بمجتمعنا العربي.\n\nبتسمحلي أسألك سؤال صغير؟"],
        ['role' => 'user', 'message' => 'احكي'],
        ['role' => 'assistant', 'message' => 'تمام، ناوي تطلع تصوت هالمرة؟'],
        ['role' => 'user', 'message' => 'لا، التصويت حرام شرعا'],
        ['role' => 'assistant', 'message' => 'بحترم التزامك. الضرورات تبيح المحظورات، والتصويت هالمرة لحماية الناس من القتل والهدم.'],
        ['role' => 'user', 'message' => 'يعني انت بدك اياي اصوت للموحدة؟'],
    ],
    'الهدم' => [
        ['role' => 'assistant', 'message' => 'يسعد اوقاتك اخ خالد شو الاخبار'],
        ['role' => 'user', 'message' => 'لا، التصويت حرام شرعا'],
        ['role' => 'assistant', 'message' => 'بحترم التزامك. التصويت هالمرة ضرورة عشان نوقف الهدم والقتل.'],
        ['role' => 'user', 'message' => 'طيب والهدم، شو دخل تصويتي فيه؟'],
    ],
];

foreach ($spots as $title => $turns) {
    $decision = DimaPrompt::nextReply($turns, 'خالد', ['stance' => 'religious', 'note' => 'مقاطع ديني', 'close' => false]);
    echo "===== {$title} =====\n";
    echo $decision['text']."\n";
    echo 'stance='.$decision['stance'].' close='.($decision['close'] ? 'yes' : 'no')."\n\n";
}
