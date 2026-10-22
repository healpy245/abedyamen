<?php

declare(strict_types=1);

use App\Services\AiChatbot\DimaPrompt;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$turns = [
    ['role' => 'assistant', 'message' => 'يسعد اوقاتك اخ Test customer شو الاخبار'],
    ['role' => 'user', 'message' => 'هلا'],
    ['role' => 'assistant', 'message' => "بعرف إنه تواصلي معك إجا فجأة، بس أنا من مركز استطلاعات وبنحاول نسمع من الناس عن الأوضاع بمجتمعنا العربي.\n\nبتسمحلي أسألك سؤال صغير؟"],
    ['role' => 'user', 'message' => 'تفضلي'],
    ['role' => 'assistant', 'message' => 'تسلم، ناوي تطلع تصوت هالمرة؟'],
    ['role' => 'user', 'message' => 'لا مش ناوي'],
    ['role' => 'assistant', 'message' => 'فهمت عليك، شو السبب؟'],
    ['role' => 'user', 'message' => 'كله عالفاضي راح نتنياهو بيجي غيره'],
];

$decision = DimaPrompt::nextReply($turns, 'خالد', ['stance' => 'no_gain', 'note' => 'مش ناوي', 'close' => false]);
echo $decision['text'], PHP_EOL;
echo 'close='.($decision['close'] ? 'yes' : 'no'), PHP_EOL;
