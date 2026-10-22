<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

/**
 * Deterministic conversation closure. Only an explicit request to stop closes the chat.
 */
class DimaConversationState
{
    /** @var list<string>|null */
    public static ?array $impactOrder = null;

    /**
     * Short explainers. impact-6 is the same file as impact-3, so it is not sent twice.
     *
     * @return list<array{id:string,disk:string,path:string,name:string,cues:string,line:string}>
     */
    public function impactClips(): array
    {
        $clips = [
            [
                'id' => 'impact-1',
                'disk' => 'public',
                'path' => 'videos/dima-impact-1.mp4',
                'name' => 'dima-impact-1.mp4',
                'cues' => 'برا|برّا|مظاه|اعترف|اعتراف|دخلت|جوا|جوّا|سنين',
                'line' => 'إذا الحكي إنه سنين وإحنا برّا، الفيديو عن 2021 لما الموحدة دخلت من جوّا والحكومة اعترفت. شوفه للآخر قبل ما نحكم.',
            ],
            [
                'id' => 'impact-2',
                'disk' => 'public',
                'path' => 'videos/dima-impact-2.mp4',
                'name' => 'dima-impact-2.mp4',
                'cues' => 'مقاطع|مشترك|معارض|نتنياهو|توصي|جرب|جرّب|اقتنع|زبط',
                'line' => 'بتقول ما اقتنعت، وجرّبنا قبل هيك. الفيديو بفرّق بين 15 عضو بالمعارضة وبين اللي صار سنة 2021. كمله للنهاية.',
            ],
            [
                'id' => 'impact-3',
                'disk' => 'public',
                'path' => 'videos/dima-impact-3.mp4',
                'name' => 'dima-impact-3.mp4',
                'cues' => 'شارع|بلد|شعار|ارض|أرض',
                'line' => 'إذا القصد الشارع والبلد مش الشعارات، الفيديو عن نفس الشارع لما الموحدة كانت جوّا. نص دقيقة، شوفه للآخر.',
            ],
            [
                'id' => 'impact-4',
                'disk' => 'public',
                'path' => 'videos/dima-impact-4.mp4',
                'name' => 'dima-impact-4.mp4',
                'cues' => 'ابن|بيت|قسيم|مسطح|بلدي|يتجوز|زواج|يبني|بنا',
                'line' => 'بخصوص البيت والقسيمة، الفيديو عن الشاب لما بده يبني، وإنه القرار مش عند البلدية لحالها. يا ريت تشوفه للآخر.',
            ],
            [
                'id' => 'impact-5',
                'disk' => 'public',
                'path' => 'videos/dima-impact-5.mp4',
                'name' => 'dima-impact-5.mp4',
                'cues' => 'جريم|عنف|قتل|ميزاني|ضحايا|نقب|مقاعد|نتيج|شايف',
                'line' => 'إذا الإحساس إنه ما في نتيجة، في فيديو قصير عن السنة اللي كانت فيها الموحدة بالحكومة. يا ريت تشوفه للآخر.',
            ],
            [
                'id' => 'impact-7',
                'disk' => 'public',
                'path' => 'videos/dima-impact-7.mp4',
                'name' => 'dima-impact-7.mp4',
                'cues' => 'هدم|رخص|جراف|كامينتس|مسطح',
                'line' => 'إذا الحكي عن الهدم والرخصة، الفيديو عن الحل بقرار قبل ما توصل الجرافة، لأن البيت انبنى بلا مسطّح. شوفه للآخر.',
            ],
        ];
        $order = self::$impactOrder;
        if ($order === null) {
            return $clips;
        }
        usort($clips, static function (array $a, array $b) use ($order): int {
            $left = array_search($a['id'], $order, true);
            $right = array_search($b['id'], $order, true);

            return ($left === false ? 99 : $left) <=> ($right === false ? 99 : $right);
        });

        return $clips;
    }

    /**
     * @param  array<string, mixed>  $prior
     * @return array{id:string,disk:string,path:string,name:string,cues:string,line:string}|null
     */
    public function pickImpactClip(array $prior, string $latest): ?array
    {
        $sent = array_values(array_filter(array_map('trim', explode(',', (string) (DimaPrompt::memoryFrom($prior['memory'] ?? null)['clips'] ?? '')))));
        $unsent = [];
        foreach ($this->impactClips() as $clip) {
            if (! in_array($clip['id'], $sent, true)) {
                $unsent[] = $clip;
            }
        }
        if ($unsent === []) {
            return null;
        }
        $best = null;
        $score = 0;
        foreach ($unsent as $clip) {
            $matched = preg_match_all('/'.$clip['cues'].'/u', $latest);
            if ($matched > $score) {
                $score = $matched;
                $best = $clip;
            }
        }
        if ($sent === [] && $this->statesOrientation($latest)) {
            if ($best !== null) {
                return $this->lineFor($best, $latest, false);
            }
            foreach ($unsent as $clip) {
                if ($clip['id'] === 'impact-5') {
                    return $this->lineFor($clip, $latest, true);
                }
            }

            return $this->lineFor($unsent[0], $latest, true);
        }

        return $score > 0 ? $this->lineFor($best, $latest, false) : null;
    }

    /**
     * @param  array{id:string,disk:string,path:string,name:string,cues:string,line:string}  $clip
     * @return array{id:string,disk:string,path:string,name:string,cues:string,line:string}
     */
    private function lineFor(array $clip, string $latest, bool $overview): array
    {
        if ($clip['id'] === 'impact-5' && $overview) {
            $clip['line'] = 'في فيديو قصير عن السنة اللي كانت فيها الموحدة بالحكومة. يا ريت تشوفه للآخر، وبعدين منكمّل براحتك.';
        }
        if ($clip['id'] === 'impact-2' && preg_match('/اقتنع/u', $latest) !== 1) {
            $clip['line'] = 'الفيديو بفرّق بين 15 عضو بالمعارضة وبين اللي صار سنة 2021. كمله للنهاية.';
        }

        return $clip;
    }

    public function statesOrientation(string $text): bool
    {
        if (str_contains($text, '؟') || str_contains($text, '?')) {
            return false;
        }

        return preg_match('/اصوت|أصوت|بصوت|مش ناوي|مش رح|ما بصوت|لا بصوت|مش شايف|نتيج|ديمقراط|الموحد|المشترك|مقاطع|ما بفرق|علفاضي|على الفاضي/u', $text) === 1;
    }

    /**
     * @param  array<string, mixed>  $prior
     */
    public function shouldSendImpactClips(array $prior, string $latest): bool
    {
        if ($this->asksToStop($latest) || $this->asksIdentity($latest) || $this->grantsPermission($latest)) {
            return false;
        }
        if (str_contains($latest, '؟') || str_contains($latest, '?')) {
            return false;
        }

        return $this->pickImpactClip($prior, $latest) !== null;
    }

    public function asksToStop(string $text): bool
    {
        $folded = str_replace(['أ', 'إ', 'آ', 'ؤ', 'ئ'], ['ا', 'ا', 'ا', 'و', 'ي'], $text);

        return preg_match('/لا تبعت|لا تبعث|لا ترسل|لا تراسل|ما تكمل|لا تكمل|لا تحكي|تبعثول|ترسلول|خلي علي|خلّي علي|خليني|خلّيني|سيبني|سيبيني|اسكت|شو دخلك|بديش احكي|ما تحكي|ما بدي اكمل|مش بدي اكمل/u', $folded) === 1;
    }

    /**
     * @param  array<string, mixed>  $prior
     * @return array{text:string,stance:string,note:string,close:bool,video:bool,stage:string,identity:mixed,memory:array<string, mixed>}
     */
    public function grantsPermission(string $text): bool
    {
        $lines = preg_split('/\n/u', trim($text)) ?: [];
        if ($lines === [] || $lines === ['']) {
            return false;
        }
        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/[!؟?.،,\s]+$/u', '', trim($line)));
            if (preg_match('/^(تفضل|تفضلي|احكي|احكيلنا|احكي السؤال|اه|آه|اها|ايوه|ايوا|اي|أي|نعم|اسأل|اسألي|اسال|اسالي|اسألني|اسالني|أكيد|اكيد|شو السؤال)$/u', $line) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    public function permissionWasRequested(array $turns): bool
    {
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') !== 'assistant') {
                continue;
            }
            if (preg_match('/بتسمحلي|بتسمحيلي|سؤال صغير/u', (string) ($turn['message'] ?? '')) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     * @param  array<string, mixed>  $prior
     */
    public function voteQuestionAlreadyAsked(array $turns, array $prior): bool
    {
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') === 'assistant' && $this->isVoteQuestion((string) ($turn['message'] ?? ''))) {
                return true;
            }
        }

        return in_array((string) ($prior['stage'] ?? ''), [
            DimaPrompt::STAGE_VOTING_INTENTION_ASKED,
            DimaPrompt::STAGE_RESPONSE_RECEIVED,
            DimaPrompt::STAGE_CLOSED,
        ], true);
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    public function statedVotingIntention(array $turns): bool
    {
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') === 'assistant') {
                continue;
            }
            $message = trim((string) ($turn['message'] ?? ''));
            if ($message !== '' && preg_match('/اصوت|أصوت|بصوت|مش رح|ما بصوت|لا بصوت|مش ناوي|ناوي اصوت|ناوي أصوت/u', $message) === 1) {
                return true;
            }
        }

        return false;
    }

    public function isVoteQuestion(string $text): bool
    {
        return preg_match('/ناوي\S{0,4}.{0,16}تصوت|تطلع\S{0,3}\s+تصوت/u', $text) === 1;
    }

    public function declinesPermission(string $text): bool
    {
        $line = trim((string) preg_replace('/[!؟?.،,\s]+$/u', '', trim($text)));

        return preg_match('/^(لا|لأ|لاء|لا شكرا|لا شكراً|مش موافق|ما بدي|بديش)$/u', $line) === 1;
    }

    public function asksIdentity(string $text): bool
    {
        return preg_match('/مين الجهة|من اي جهة|من أي جهة|مين بحكي|مين معي|مين معاي|من وين بتحكي/u', $text) === 1;
    }

    public function votingQuestion(?string $name): string
    {
        $female = trim((string) $name) !== '' && MansourPrompt::isFemaleName($name);

        return $female ? 'ناوية تصوتي بالانتخابات الجاي؟' : 'ناوي تصوت بالانتخابات الجاي؟';
    }

    public function noVoteReply(?string $name): string
    {
        $female = trim((string) $name) !== '' && MansourPrompt::isFemaleName($name);
        $said = $female ? 'شو قلتي' : 'شو قلت';
        $want = $female ? 'بدكي' : 'بدك';
        $why = $female
            ? 'بقدر أعرف منك شو السبب الي مخليكي تقاطعي وما تصوّتي؟'
            : 'بقدر أعرف منك شو السبب الي مخليك تقاطع وما تصوت؟';

        return "وبعد ها الفيديو {$said}؟ {$want} نعيش كمان 4 سنين بهالحكومة العنصرية؟\n4 سنين عشنا فيها القتل والهدم والخوف، 1000 قتيل ومئات البيوت الي تهدمت.\n{$why}";
    }

    public function withoutTrailingQuestion(string $text, string $latest, string $previousAssistant): string
    {
        $previousAsked = str_contains($previousAssistant, '؟') || str_contains($previousAssistant, '?');
        $latestAsks = str_contains($latest, '؟') || str_contains($latest, '?');
        if (! $previousAsked || $latestAsks || mb_strlen(trim($latest)) < 12) {
            return $text;
        }
        $parts = preg_split('/(?<=[.!?؟])\s+/u', trim($text)) ?: [];
        if (count($parts) < 2) {
            return $text;
        }
        $last = (string) $parts[array_key_last($parts)];
        if (! str_contains($last, '؟') && ! str_contains($last, '?')) {
            return $text;
        }
        array_pop($parts);

        return trim(implode(' ', $parts));
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    public function previousAssistant(array $turns): string
    {
        $previous = '';
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') === 'assistant') {
                $line = trim((string) ($turn['message'] ?? ''));
                if ($line !== '') {
                    $previous = $line;
                }
            }
        }

        return $previous;
    }

    public function whoQuestion(?string $name): string
    {
        return $this->female($name) ? 'لمين رح تصوتي المره هاي؟' : 'لمين رح تصوت المره هاي؟';
    }

    public function unitedThanks(?string $name): string
    {
        $you = $this->female($name) ? 'الكي' : 'الك';

        return 'تمام شكرا '.$you.' كتير على وقتك، نهارك سعيد 🌷';
    }

    public function democratsReply(?string $name): string
    {
        $vote = $this->female($name) ? 'بتصوتي' : 'بتصوت';
        $you = $this->female($name) ? 'بتصوّتي' : 'بصوّت';

        return 'غريب إنه نسمع عربي '.$you.' لحزب مثل الديمقراطيين. غولان كان نائب عن ميرتس وصار رئيس العمل بأيار 2024، ومعاه لزيمي وكريف وريتن. تجنيد للكل يعني الخدمة العسكرية على كل المواطنين ومنهم احنا العرب، والانفصال عن الفلسطينيين يعني ينفصلوا عن الشعب الفلسطيني والأمن يضل بإيدهم. ليش ما '.$vote.' لحزب مثل الموحدة؟ حزب عربي بدعم قضيتنا ومصالح شعبنا، وبجيب ميزانيات لتحسين وضعنا.';
    }

    public function jointReply(?string $name): string
    {
        $watch = $this->female($name) ? 'تشوفيه' : 'تشوفه';

        return 'تمام، المشتركة خيار بنحترمه. في فيديو قصير عن الفرق بين 15 عضو كانوا بالمعارضة وبين سنة 2021. يا ريت '.$watch.' للآخر، وبعدين منحكي براحتك.';
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    public function voteTarget(string $latest, array $turns): ?string
    {
        if ($this->asksToStop($latest)) {
            return null;
        }
        $folded = $this->fold($latest);
        $answering = $this->isWhoQuestion($this->previousAssistant($turns));
        $choosing = preg_match('/بصوت|اصوت|أصوت|مصوت|رح اصوت|رح أصوت/u', $folded) === 1;
        $question = str_contains($latest, '؟') || str_contains($latest, '?');
        if ($question && ! $choosing && ! $answering) {
            return null;
        }
        if (preg_match('/الفرق|بختلف|مقارن|بينهم|مقابل/u', $folded) === 1) {
            return null;
        }
        if ($this->forUnited($folded, $answering)) {
            return 'united';
        }
        if ($this->forDemocrats($folded, $answering, $choosing)) {
            return 'democrats';
        }
        if ($this->forJoint($folded, $answering, $choosing)) {
            return 'joint';
        }

        return null;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    public function affirmsVoteOnly(string $latest, array $turns): bool
    {
        $previous = $this->previousAssistant($turns);
        if (! $this->isVoteQuestion($previous) || $this->isWhoQuestion($previous) || $this->voteTarget($latest, $turns) !== null) {
            return false;
        }
        $line = trim((string) preg_replace('/[!؟?.،,\s]+$/u', '', trim($latest)));
        if ($line === '' || mb_strlen($line) > 48) {
            return false;
        }
        $folded = $this->fold($line);

        return preg_match('/^(اكيد|اه|اها|ايوه|ايوا|اي|نعم|طبعا|تمام|ان شاء الله|رح اصوت|بده اصوت|بدي اصوت|ناوي|ناويه|اكيد رح اصوت|اه رح اصوت|نعم بصوت)$/u', $folded) === 1;
    }

    public function isWhoQuestion(string $text): bool
    {
        return preg_match('/لمين.{0,12}تصوت/u', $text) === 1;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    public function alreadySaid(array $turns, string $needle): bool
    {
        foreach ($turns as $turn) {
            if (($turn['role'] ?? '') === 'assistant' && str_contains((string) ($turn['message'] ?? ''), $needle)) {
                return true;
            }
        }

        return false;
    }

    public function identityAnswer(?string $name, bool $includeVote): string
    {
        $who = 'أنا ديما من مركز استطلاعات للوسط العربي';
        if (! $includeVote) {
            return $who.'.';
        }
        $female = trim((string) $name) !== '' && MansourPrompt::isFemaleName($name);
        $bother = $female ? 'ضايقتك' : 'ضايقك';
        $go = $female ? 'هل رح تطلعي تصوتي هاي المرة ولا كيف؟' : 'هل رح تطلع تصوت هاي المرة ولا كيف؟';

        return "{$who}، بتمنى ما أكون {$bother}، بس حبيت أستفسر وأسألك بالنسبة للانتخابات، {$go}";
    }

    /**
     * @param  array<string, mixed>  $prior
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    public function shouldAskVotingIntention(array $turns, array $prior, string $latest): bool
    {
        return $this->grantsPermission($latest)
            && $this->permissionWasRequested($turns)
            && ! $this->voteQuestionAlreadyAsked($turns, $prior)
            && ! $this->statedVotingIntention($turns)
            && ! $this->asksIdentity($latest);
    }

    public function stopped(array $prior): array
    {
        $memory = DimaPrompt::memoryFrom($prior['memory'] ?? null);
        $memory['wants_to_continue'] = false;

        return [
            'text' => 'تمام، ما برجعلش رسالة.',
            'stance' => DimaPrompt::REFUSED,
            'note' => '',
            'close' => true,
            'video' => false,
            'stage' => DimaPrompt::STAGE_CLOSED,
            'identity' => $prior['identity'] ?? null,
            'memory' => $memory,
        ];
    }

    private function female(?string $name): bool
    {
        return trim((string) $name) !== '' && MansourPrompt::isFemaleName($name);
    }

    private function fold(string $text): string
    {
        $text = str_replace(['أ', 'إ', 'آ', 'ى', 'ة', 'ؤ', 'ئ', 'ـ', 'ّ'], ['ا', 'ا', 'ا', 'ي', 'ه', 'و', 'ي', '', ''], $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function forUnited(string $folded, bool $answering): bool
    {
        if (preg_match('/مش للموحد|مو للموحد|ما بصوت للموحد|ضد الموحد/u', $folded) === 1) {
            return false;
        }
        if (preg_match('/للموحد|مع الموحد|بصوت موحد|مصوت موحد|רע״ם|רע"ם|راعام|راعم/u', $folded) === 1) {
            return true;
        }

        return $answering && preg_match('/^(ال)?موحده$/u', $folded) === 1;
    }

    private function forDemocrats(string $folded, bool $answering, bool $choosing): bool
    {
        if (! DimaFacts::mentionsDemocrats($folded)) {
            return false;
        }

        return $choosing || $answering || preg_match('/الديمقراطي|الدمقراطي|דמוקרטים/u', $folded) === 1;
    }

    private function forJoint(string $folded, bool $answering, bool $choosing): bool
    {
        if (preg_match('/مشترك|ודם|ودم/u', $folded) !== 1) {
            return false;
        }

        return $choosing || $answering;
    }
}
