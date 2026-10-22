<?php

declare(strict_types=1);

use App\Services\AiChatbot\DimaPrompt;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$only = $argv[1] ?? '';
if ($only === '') {
    fwrite(STDERR, "usage: php tools/dima_live_scenarios.php <name>\n");
    exit(1);
}

$scenarios = scenarios();
if (! isset($scenarios[$only])) {
    fwrite(STDERR, "unknown {$only}\n");
    exit(1);
}

$result = runScenario($scenarios[$only]);
$dir = __DIR__.'/../storage/app/dima-check';
if (! is_dir($dir)) {
    mkdir($dir, 0777, true);
}
file_put_contents($dir.'/'.$only.'.json', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo $only.' '.($result['ok'] ? 'OK' : 'FAIL')."\n";
foreach ($result['issues'] as $issue) {
    echo ' - '.$issue."\n";
}

/**
 * @param  array{name:string,opening:string,steps:list<list<string>>,expect:list<array<string,mixed>>}  $scenario
 * @return array{ok:bool,issues:list<string>,log:list<array<string,mixed>>}
 */
function runScenario(array $scenario): array
{
    $turns = [
        ['role' => 'assistant', 'message' => $scenario['opening']],
    ];
    $prior = ['stance' => DimaPrompt::OPEN, 'note' => '', 'close' => false];
    $log = [['who' => 'dima', 'text' => $scenario['opening']]];
    $issues = [];

    foreach ($scenario['steps'] as $index => $step) {
        foreach ($step as $message) {
            $turns[] = ['role' => 'user', 'message' => $message];
            $log[] = ['who' => 'person', 'text' => $message];
        }
        $decision = DimaPrompt::nextReply($turns, $scenario['name'], $prior);
        $text = trim($decision['text']);
        $turns[] = ['role' => 'assistant', 'message' => $text];
        $prior = [
            'stance' => $decision['stance'],
            'note' => $decision['note'],
            'close' => $decision['close'],
        ];
        $log[] = [
            'who' => 'dima',
            'text' => $text,
            'stance' => $decision['stance'],
            'close' => $decision['close'],
            'note' => $decision['note'],
        ];
        $expect = $scenario['expect'][$index] ?? [];
        $issues = array_merge($issues, judge($index, $text, $decision, $expect));
    }

    return ['ok' => $issues === [], 'issues' => $issues, 'log' => $log];
}

/**
 * @param  array{text:string,stance:string,note:string,close:bool}  $decision
 * @param  array<string,mixed>  $expect
 * @return list<string>
 */
function judge(int $index, string $text, array $decision, array $expect): array
{
    $step = 'step '.($index + 1);
    $issues = [];
    if ($text === '') {
        $issues[] = "{$step}: empty reply";

        return $issues;
    }
    if (preg_match('/[«»“”<>]/u', $text) === 1) {
        $issues[] = "{$step}: weird quotes";
    }
    if (preg_match('/[\x{064B}-\x{0652}]/u', $text) === 1) {
        $issues[] = "{$step}: tashkeel leaked";
    }
    if (isset($expect['stance']) && $decision['stance'] !== $expect['stance']) {
        $issues[] = "{$step}: stance {$decision['stance']} wanted {$expect['stance']}";
    }
    if (array_key_exists('close', $expect) && $decision['close'] !== $expect['close']) {
        $issues[] = "{$step}: close ".($decision['close'] ? 'true' : 'false');
    }
    if (! empty($expect['has']) && ! str_contains($text, (string) $expect['has'])) {
        $issues[] = "{$step}: missing {$expect['has']}";
    }
    if (! empty($expect['question']) && ! str_contains($text, '؟')) {
        $issues[] = "{$step}: no question";
    }
    if (! empty($expect['not_goodbye']) && preg_match('/يعطيك العافية|شاركتني|بحترم خيارك/u', $text) === 1 && ! str_contains($text, '؟')) {
        $issues[] = "{$step}: closed with thanks";
    }
    if (! empty($expect['short']) && mb_strlen($text) > 380) {
        $issues[] = "{$step}: too long (".mb_strlen($text).')';
    }
    if (preg_match('/معلومات محايدة|ما بحاول|مش رح أحاول|مش رح احاول|ما برجح|بقدر أساعدك|بقدر اساعدك|نترك موضوع|صياغة استطلاع|من غير ما أغير|مش رح أغيره/u', $text) === 1) {
        $issues[] = "{$step}: neutrality refusal";
    }

    return $issues;
}

/**
 * @return array<string, array{name:string,opening:string,steps:list<list<string>>,expect:list<array<string,mixed>>}>
 */
function scenarios(): array
{
    $intro = ['has' => 'مركز استطلاعات', 'question' => true, 'close' => false, 'stance' => DimaPrompt::OPEN, 'short' => true];

    return [
        'joint' => [
            'name' => 'يامن',
            'opening' => 'يسعد اوقاتك اخ يامن شو الاخبار',
            'steps' => [
                ['يا هلا', 'مش فاهم، مين معي.', '؟'],
                ['اهلين تفضلي'],
                ['طبعا طبعا'],
                ['بس مشتركه'],
                ['بس بدي الكل يتجمع'],
            ],
            'expect' => [
                $intro,
                ['question' => true, 'close' => false, 'short' => true],
                ['question' => true, 'close' => false, 'stance' => DimaPrompt::OPEN, 'short' => true],
                ['stance' => DimaPrompt::JOINT, 'close' => false, 'question' => true, 'not_goodbye' => true, 'short' => true],
                ['stance' => DimaPrompt::JOINT, 'close' => false, 'question' => true, 'not_goodbye' => true],
            ],
        ],
        'united' => [
            'name' => 'احمد',
            'opening' => 'يسعد اوقاتك اخ احمد شو الاخبار',
            'steps' => [
                ['هلا'],
                ['تفضل'],
                ['اه بصوت'],
                ['للموحدة'],
            ],
            'expect' => [
                $intro,
                ['question' => true, 'close' => false, 'short' => true],
                ['question' => true, 'close' => false, 'short' => true],
                ['stance' => DimaPrompt::UNITED, 'close' => true, 'short' => true],
            ],
        ],
        'religious' => [
            'name' => 'خالد',
            'opening' => 'يسعد اوقاتك اخ خالد شو الاخبار',
            'steps' => [
                ['هلا'],
                ['احكي'],
                ['لا، التصويت حرام شرعا'],
            ],
            'expect' => [
                $intro,
                ['question' => true, 'close' => false, 'short' => true],
                ['stance' => DimaPrompt::RELIGIOUS, 'close' => false, 'question' => true, 'not_goodbye' => true, 'short' => true],
            ],
        ],
        'identity' => [
            'name' => 'سامر',
            'opening' => 'يسعد اوقاتك اخ سامر شو الاخبار',
            'steps' => [
                ['اهلا'],
                ['يلا'],
                ['ما بصوت، هاد اعتراف بدولتهم'],
            ],
            'expect' => [
                $intro,
                ['question' => true, 'close' => false, 'short' => true],
                ['stance' => DimaPrompt::IDENTITY, 'close' => false, 'not_goodbye' => true, 'short' => true],
            ],
        ],
        'no_gain' => [
            'name' => 'مازن',
            'opening' => 'يسعد اوقاتك اخ مازن شو الاخبار',
            'steps' => [
                ['هلا'],
                ['احكي'],
                ['بصوت بس كلهم ما بسووا اشي'],
            ],
            'expect' => [
                $intro,
                ['question' => true, 'close' => false, 'short' => true],
                ['stance' => DimaPrompt::NO_GAIN, 'close' => false, 'question' => true, 'not_goodbye' => true],
            ],
        ],
        'zionist' => [
            'name' => 'رامي',
            'opening' => 'يسعد اوقاتك اخ رامي شو الاخبار',
            'steps' => [
                ['هلا'],
                ['تفضل'],
                ['بصوت لميرتس، احسن من الكل'],
            ],
            'expect' => [
                $intro,
                ['question' => true, 'close' => false, 'short' => true],
                ['stance' => DimaPrompt::ZIONIST, 'close' => false, 'question' => true, 'not_goodbye' => true],
            ],
        ],
        'hostile' => [
            'name' => 'نبيل',
            'opening' => 'يسعد اوقاتك اخ نبيل شو الاخبار',
            'steps' => [
                ['شو دخلك فيي'],
                ['خلص ما تكمل'],
            ],
            'expect' => [
                ['close' => false, 'question' => true, 'short' => true, 'stance' => DimaPrompt::OPEN],
                ['stance' => DimaPrompt::REFUSED, 'close' => true, 'short' => true],
            ],
        ],
        'hesitant' => [
            'name' => 'فادي',
            'opening' => 'يسعد اوقاتك اخ فادي شو الاخبار',
            'steps' => [
                ['اهلا'],
                ['والله لسا مش عارف اذا بدي اصوت'],
            ],
            'expect' => [
                $intro,
                ['close' => false, 'question' => true, 'short' => true],
            ],
        ],
        'woman' => [
            'name' => 'سارة',
            'opening' => 'يسعد اوقاتك اخت سارة شو الاخبار',
            'steps' => [
                ['هلا'],
                ['تفضلي'],
                ['طبعا'],
                ['المشتركة'],
            ],
            'expect' => [
                ['has' => 'مركز استطلاعات', 'question' => true, 'close' => false, 'short' => true],
                ['question' => true, 'close' => false, 'short' => true],
                ['question' => true, 'close' => false, 'short' => true],
                ['stance' => DimaPrompt::JOINT, 'close' => false, 'question' => true, 'not_goodbye' => true, 'short' => true],
            ],
        ],
        'bare_no' => [
            'name' => 'عمر',
            'opening' => 'يسعد اوقاتك اخ عمر شو الاخبار',
            'steps' => [
                ['هلا'],
                ['لا'],
            ],
            'expect' => [
                $intro,
                ['close' => false, 'question' => true, 'short' => true],
            ],
        ],
    ];
}
