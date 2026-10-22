<?php

declare(strict_types=1);

use App\Services\AiChatbot\DimaPrompt;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$chats = [
    [
        'title' => 'يامن، مشتركة',
        'name' => 'يامن',
        'opening' => 'يسعد اوقاتك اخ يامن شو الاخبار',
        'steps' => [
            ['يا هلا', 'مش فاهم، مين معي.'],
            ['اهلين تفضلي'],
            ['اه بصوت'],
            ['المشتركة'],
            ['ليش، بحسها بتجمعنا'],
            ['بس وصلوا 15 مقعد وما منعوا ولا قانون'],
            ['والموحدة دخلت عالحكومة، هاد تنازل بنظري'],
            ['طيب اقنعيني، شو بتختلف؟'],
            ['خليني افكر بالموضوع'],
        ],
    ],
    [
        'title' => 'خالد، مقاطع ديني',
        'name' => 'خالد',
        'opening' => 'يسعد اوقاتك اخ خالد شو الاخبار',
        'steps' => [
            ['هلا'],
            ['احكي'],
            ['لا، التصويت حرام شرعا'],
            ['شيخ المسجد عندنا بقول المشاركة اعتراف'],
            ['والناس عم تنقتل، بس هاد مش بسبب صوتي'],
            ['يعني انت بدك اياي اصوت للموحدة؟'],
            ['مش مقتنع، وضحي اكثر'],
            ['طيب والهدم، شو دخل تصويتي فيه؟'],
            ['اذا في شيخ بيحكي غير هيك بسمع'],
        ],
    ],
];

$dir = __DIR__.'/../storage/app/dima-check';
if (! is_dir($dir)) {
    mkdir($dir, 0777, true);
}

$all = [];
foreach ($chats as $chat) {
    $turns = [['role' => 'assistant', 'message' => $chat['opening']]];
    $prior = ['stance' => DimaPrompt::OPEN, 'note' => '', 'close' => false];
    $log = [['who' => 'ديما', 'text' => $chat['opening']]];
    echo $chat['title']."\n";
    foreach ($chat['steps'] as $step) {
        foreach ($step as $message) {
            $turns[] = ['role' => 'user', 'message' => $message];
            $log[] = ['who' => $chat['name'], 'text' => $message];
            echo '  > '.$message."\n";
        }
        $started = microtime(true);
        $decision = DimaPrompt::nextReply($turns, $chat['name'], $prior);
        $seconds = (int) round(microtime(true) - $started);
        $text = trim($decision['text']);
        if ($text === '' && $seconds < 3) {
            echo "STOP no credit\n";
            file_put_contents($dir.'/two-deep.json', json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            exit(2);
        }
        $turns[] = ['role' => 'assistant', 'message' => $text];
        $prior = ['stance' => $decision['stance'], 'note' => $decision['note'], 'close' => $decision['close']];
        $log[] = ['who' => 'ديما', 'text' => $text, 'stance' => $decision['stance'], 'close' => $decision['close']];
        echo '  < '.$text."\n";
    }
    $all[] = ['title' => $chat['title'], 'log' => $log];
    file_put_contents($dir.'/two-deep.json', json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

echo "DONE\n";
