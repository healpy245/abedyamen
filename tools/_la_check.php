<?php

require __DIR__.'/../vendor/autoload.php';

$links = ['aswatkum' => '', 'muwahada' => '', 'video' => ''];
$variants = ['لأ', 'لا', 'لاء', 'لإ', 'لاأ', 'لأا', 'لاا', 'لا ء'];
foreach ($variants as $word) {
    $turns = [
        ['role' => 'assistant', 'message' => 'عندك تطبيق أصواتكم؟'],
        ['role' => 'user', 'message' => 'نعم'],
        ['role' => 'assistant', 'message' => 'تمام. افتح التطبيق واعطي صلاحية لمشاركة الأرقام على جهازك داخل التطبيق. ابعثلي تم لما تخلّص.'],
        ['role' => 'user', 'message' => 'تم'],
        ['role' => 'assistant', 'message' => 'للدخول للتطبيق انت بحاجة لاسم مستخدم، في معك اسم مستخدم؟'],
        ['role' => 'user', 'message' => $word],
    ];
    $r = App\Services\AiChatbot\AppsPrompt::nextReply($turns, 'هند', $links);
    echo $word.' => '.str_replace("\n", ' | ', $r['text'])."\n";
}
