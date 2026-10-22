<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$decision = App\Services\AiChatbot\DimaPrompt::nextReply([
    ['role' => 'assistant', 'message' => 'يسعد اوقاتك اخ يامن شو الاخبار'],
    ['role' => 'user', 'message' => 'يا هلا'],
    ['role' => 'user', 'message' => 'مش فاهم، مين معي.'],
    ['role' => 'assistant', 'message' => "بعرف إنه تواصلي معك إجا فجأة، معك ديما من مركز استطلاعات وبنحاول نسمع من الناس عن الأوضاع بمجتمعنا العربي.\n\nبتسمحلي أسألك سؤال صغير؟"],
    ['role' => 'user', 'message' => 'اهلين تفضلي'],
    ['role' => 'assistant', 'message' => 'تسلم، ناوي تطلع تصوت هالمرة؟'],
    ['role' => 'user', 'message' => 'طبعا طبعا'],
    ['role' => 'assistant', 'message' => 'لمين ناوي تصوت؟ وإذا ما بدك تسمي برضو تمام.'],
    ['role' => 'user', 'message' => 'بس مشتركه'],
], 'يامن', ['stance' => 'open', 'note' => '', 'close' => false]);

echo json_encode($decision, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
