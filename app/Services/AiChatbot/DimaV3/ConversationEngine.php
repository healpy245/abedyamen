<?php

declare(strict_types=1);

namespace App\Services\AiChatbot\DimaV3;

use App\Services\AiChatbot\DimaPrompt;
use App\Services\AiChatbot\MansourPrompt;
use App\Services\AiChatbot\MowahadaOpenAi;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One model call per ordinary turn. Opt-out and permission refusal skip the model.
 */
class ConversationEngine
{
    public static ?Closure $testResponder = null;

    public function __construct(
        private readonly ConversationFlow $flow,
        private readonly ConversationState $state,
        private readonly MediaLibrary $media,
        private readonly TurnLogger $logger,
        private readonly KnowledgeBase $knowledge,
        private readonly ObjectionReplies $objections,
        private readonly ArgumentLibrary $arguments,
    ) {}

    public const INTRO_LINE = 'متأسفة إذا رسالتي إجت فجأة. أنا ديما، وبحب أسمع رأي الناس بالوسط العربي عن الانتخابات الجاي. بتسمحلي أسألك سؤال صغير؟';

    public const INTRO_LINE_FEMALE = 'متأسفة إذا رسالتي إجت فجأة. أنا ديما، وبحب أسمع رأي الناس بالوسط العربي عن الانتخابات الجاي. بتسمحيلي أسألك سؤال صغير؟';

    /**
     * Female names the shared Mansour list misses. Kept here so the live
     * Mansour bot's addressing does not change.
     */
    private const EXTRA_FEMALE = ['ريم', 'رهف', 'روان', 'رؤى', 'سجى', 'ضحى', 'دعاء', 'رشا', 'ربى', 'ربا', 'لبنى', 'سناء', 'هناء', 'ريهام', 'رهام', 'مها', 'نوال', 'عرين', 'اريج', 'أريج', 'ايناس', 'إيناس', 'رحاب', 'سوزان', 'ميار', 'ملك', 'تسنيم', 'غدير', 'وعد', 'رندا', 'صبا', 'هيام', 'ابتهال', 'وجدان', 'رانيا', 'ميسون', 'سوار', 'نغم', 'شهد', 'روند', 'ريتا', 'رنيم', 'جنى', 'لميس'];

    public static function isFemale(?string $name): bool
    {
        $clean = trim((string) $name);
        if ($clean === '') {
            return false;
        }
        $given = preg_split('/\s+/u', $clean)[0] ?? '';

        return in_array($given, self::EXTRA_FEMALE, true) || MansourPrompt::isFemaleName($clean);
    }

    /**
     * Answer to a plain "who is this?": who Dima talks to and why, in one of a few
     * wordings so it does not read like a script. It does not claim to be a poll.
     *
     * @var list<string>
     */
    private const WHO_LINES = [
        'أنا ديما 😊 بنحكي مع الناس بالوسط العربي قبل الانتخابات، بنشجعهم يطلعوا يصوتوا وبنحاول نفهم الأوضاع وشو توجهات الناس بشكل عام.',
        'أنا ديما، بتواصل مع الناس بمجتمعنا العربي. هدفنا نشجع على التصويت، ونسمع من الناس عن أوضاعهم وتوجهاتهم.',
        'معك ديما 😊 بنحكي مع الناس بالوسط العربي، بنشجع على المشاركة بالانتخابات وبنحاول نعرف كيف الأوضاع وشو رأي الناس.',
        'أنا ديما، بحكي مع ناس من مجتمعنا قبل الانتخابات. بنشجع الكل يصوت، وبهمنا نعرف كيف الأوضاع وشو توجهات الناس.',
    ];

    public static function whoLine(): string
    {
        return self::WHO_LINES[random_int(0, count(self::WHO_LINES) - 1)];
    }

    public static function noVoteLine(bool $female): string
    {
        return $female
            ? "وبعد هالفيديو شو قلتي؟ بدكي نعيش كمان 4 سنين بهالحكومة العنصرية؟\n4 سنين عشنا فيها القتل والهدم والخوف، 1000 قتيل ومئات البيوت الي تهدمت.\nبقدر أعرف منك شو السبب الي مخليكي تقاطعي وما تصوتي؟"
            : "وبعد هالفيديو شو قلت؟ بدك نعيش كمان 4 سنين بهالحكومة العنصرية؟\n4 سنين عشنا فيها القتل والهدم والخوف، 1000 قتيل ومئات البيوت الي تهدمت.\nبقدر أعرف منك شو السبب الي مخليك تقاطع وما تصوت؟";
    }

    /**
     * The first message is only a short hello. The introduction comes after the person answers.
     */
    public static function openingFor(?string $name): string
    {
        $clean = trim((string) $name);

        return $clean === '' ? 'مرحبا، شو الاخبار' : 'مرحبا '.$clean.'، شو الاخبار';
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     * @param  array<string, mixed>  $prior
     * @return array{text:string,stance:string,note:string,close:bool,video:bool,stage:string,identity:array<string, mixed>,memory:array<string, mixed>,clips:list<array<string, string>>}
     */
    public function turn(array $turns, ?string $name, array $prior = [], bool $live = false, int $conversationId = 0): array
    {
        $memory = $this->state->normalize($prior);
        $latest = $this->latestUserText($turns);
        if ($memory['opted_out'] || $memory['current_stage'] === ConversationFlow::CLOSED) {
            return $this->closed($memory, $conversationId, 'المحادثة مقفلة.');
        }
        if ($this->isOptOut($latest)) {
            return $this->closed($memory, $conversationId, $latest);
        }
        // Reply to the hello is always the same soft introduction, without a model call.
        if ($memory['current_stage'] === ConversationFlow::GREETING && $this->openingWasSent($turns)) {
            $memory['current_stage'] = ConversationFlow::PERMISSION;
            $female = trim((string) $name) !== '' && self::isFemale($name);
            $decision = $this->pack($memory, $female ? self::INTRO_LINE_FEMALE : self::INTRO_LINE, false, []);
            $this->logger->write($conversationId, $decision['stage'], false, '', 0, $latest, $decision['text']);

            return $decision;
        }
        // A plain "who is this?" the first time: just "أنا ديما", then the open question again.
        // Pressing ("مين وراكي", "لمين بتشتغلي", "بوت") or asking twice goes to the model, which answers honestly.
        if ($memory['current_stage'] !== ConversationFlow::GREETING
            && ! in_array('سؤال مين', $memory['previously_answered_topics'], true)
            && $this->asksPlainWho($latest)) {
            $memory['previously_answered_topics'][] = 'سؤال مين';
            $previous = $this->previousAssistantText($turns);
            $female = self::isFemale($name);
            $again = match (true) {
                $memory['current_stage'] === ConversationFlow::PERMISSION => $female ? 'بتسمحيلي أسألك سؤال صغير؟' : 'بتسمحلي أسألك سؤال صغير؟',
                str_ends_with($previous, '؟') => (string) preg_replace('/^.*\n/su', '', $previous),
                default => '',
            };
            $text = trim(self::whoLine()."\n".$again);
            $decision = $this->pack($memory, $text, false, []);
            $this->logger->write($conversationId, $decision['stage'], false, '', 0, $latest, $decision['text']);

            return $decision;
        }
        // A plain yes to the permission question gets the vote question, without a model call.
        if ($memory['current_stage'] === ConversationFlow::PERMISSION && $memory['permission_status'] !== 'granted' && $this->isConsent($latest)) {
            $memory['permission_status'] = 'granted';
            $memory['current_stage'] = ConversationFlow::VOTING_INTENTION;
            $female = trim((string) $name) !== '' && self::isFemale($name);
            $decision = $this->pack($memory, $female ? 'ناوية تصوتي بالانتخابات الجاي؟' : 'ناوي تصوت بالانتخابات الجاي؟', false, []);
            $this->logger->write($conversationId, $decision['stage'], false, '', 0, $latest, $decision['text']);

            return $decision;
        }
        // A clear "no, I won't vote" gets the Ben Gvir clip and the campaign's fixed line.
        if ($memory['current_stage'] === ConversationFlow::VOTING_INTENTION
            && ! in_array('no-vote', $memory['sent_media_ids'], true)
            && DimaPrompt::explicitNoVote($turns)) {
            $female = trim((string) $name) !== '' && self::isFemale($name);
            $text = self::noVoteLine($female);
            $clip = $this->media->attach('no-vote', $text, $memory['sent_media_ids']);
            $memory['voting_intention'] = 'no';
            $memory['current_stage'] = ConversationFlow::OPEN;
            $memory['sent_media_ids'][] = 'no-vote';
            $memory['previously_answered_topics'][] = 'فيديو المقاطعة وسؤال السبب';
            $decision = $this->pack($memory, $text, false, $clip === null ? [] : [$clip]);
            $this->logger->write($conversationId, $decision['stage'], false, 'no-vote', 0, $latest, $decision['text']);

            return $decision;
        }
        // Short, clear answers ("لا", "للمشتركة", "كله علفاضي") are decided by the word lists.
        // Anything longer is read by the model first: word lists misread sentences
        // ("ما بفكر اصوت" is not "still thinking"). They stay as the fallback.
        $short = $this->isShortAnswer($latest);
        if ($short) {
            $listed = $this->wordListReply($turns, $name, $memory, $latest, $conversationId);
            if ($listed !== null) {
                return $listed;
            }
        }
        if ($memory['permission_status'] !== 'granted' && $this->isPermissionRefusal($memory['current_stage'], $latest)) {
            $memory['permission_status'] = 'refused';

            return $this->closed($memory, $conversationId, $latest);
        }

        $messages = $this->messages($turns, $name, $memory);
        $raw = $this->complete($messages, $live);
        $parsed = $this->decode($raw);
        if ($parsed === null && ! $short) {
            $listed = $this->wordListReply($turns, $name, $memory, $latest, $conversationId);
            if ($listed !== null) {
                return $listed;
            }
        }
        if ($parsed === null) {
            $decision = $this->pack($memory, 'ما وصلني رد واضح هلق.', false, []);
            $this->logger->write($conversationId, $decision['stage'], false, '', 1, (string) $raw, $decision['text']);

            return $decision;
        }

        // The model understood what the person meant; where a campaign reply fits, that reply answers.
        $routed = $this->route($parsed, $memory, $turns, $name, $latest);
        if ($routed !== null) {
            [$memory, $text, $clips, $close] = $routed;
            if ($close) {
                return $this->closed($memory, $conversationId, $latest);
            }
            $decision = $this->pack($memory, $text, false, $clips);
            $this->logger->write($conversationId, $decision['stage'], false, $clips[0]['id'] ?? '', 1, (string) $raw, $decision['text']);

            return $decision;
        }
        if (! $short && trim((string) ($parsed['intent'] ?? '')) === '') {
            $listed = $this->wordListReply($turns, $name, $memory, $latest, $conversationId);
            if ($listed !== null) {
                return $listed;
            }
        }

        // Permission first: the same message can grant it and answer the vote question.
        $permission = (string) ($parsed['permission_status'] ?? '');
        if (in_array($permission, ['granted', 'refused'], true) && $memory['current_stage'] !== ConversationFlow::GREETING) {
            $memory['permission_status'] = $permission;
        }
        $proposed = $this->flow->normalize((string) ($parsed['stage'] ?? ''));
        if ($proposed !== '' && $this->flow->accepts($memory['current_stage'], $proposed, $memory['permission_status'] === 'granted')) {
            $memory['current_stage'] = $proposed;
        }
        $vote = (string) ($parsed['voting_intention'] ?? '');
        if (in_array($vote, ['yes', 'no'], true) && in_array($memory['current_stage'], [
            ConversationFlow::VOTING_INTENTION,
            ConversationFlow::PARTY_PREFERENCE,
            ConversationFlow::OPEN,
        ], true)) {
            $memory['voting_intention'] = $vote;
        }
        $party = trim((string) ($parsed['party_mentioned'] ?? ''));
        if ($party !== '') {
            $memory['party_mentioned'] = mb_substr($party, 0, 120);
        }
        $question = trim((string) ($parsed['last_user_question'] ?? ''));
        if ($question !== '') {
            $memory['last_user_question'] = mb_substr($question, 0, 240);
        }
        $topic = trim((string) ($parsed['answered_topic'] ?? ''));
        if ($topic !== '' && ! in_array($topic, $memory['previously_answered_topics'], true)) {
            $memory['previously_answered_topics'][] = mb_substr($topic, 0, 160);
        }

        $reply = $this->objections->stripBackingOff(trim((string) ($parsed['reply'] ?? '')));
        if ($reply === '') {
            $reply = 'ما وصلني رد واضح هلق.';
        }
        $clip = $this->media->attach(
            trim((string) ($parsed['media_id'] ?? '')),
            trim((string) ($parsed['media_description'] ?? '')),
            $memory['sent_media_ids'],
        );
        $clips = [];
        if ($clip !== null) {
            $clips[] = $clip;
            $memory['sent_media_ids'][] = $clip['id'];
        }
        $decision = $this->pack($memory, $reply, false, $clips);
        $this->logger->write($conversationId, $decision['stage'], false, $clip['id'] ?? '', 1, (string) $raw, $decision['text']);

        return $decision;
    }

    /**
     * @param  array<string, mixed>  $memory
     * @return array{text:string,stance:string,note:string,close:bool,video:bool,stage:string,identity:array<string, mixed>,memory:array<string, mixed>,clips:list<array<string, string>>}
     */
    private function closed(array $memory, int $conversationId, string $raw): array
    {
        $memory['opted_out'] = true;
        $memory['current_stage'] = ConversationFlow::CLOSED;
        $decision = $this->pack($memory, 'تمام، بوقف هون وما برجع أراسلك.', true, []);
        $this->logger->write($conversationId, ConversationFlow::CLOSED, true, '', 0, $raw, $decision['text']);

        return $decision;
    }

    /**
     * @param  array<string, mixed>  $memory
     * @param  list<array<string, string>>  $clips
     * @return array{text:string,stance:string,note:string,close:bool,video:bool,stage:string,identity:array<string, mixed>,memory:array<string, mixed>,clips:list<array<string, string>>}
     */
    private function pack(array $memory, string $text, bool $close, array $clips): array
    {
        $memory = $this->state->normalize($memory);

        return [
            'text' => $text,
            'stance' => $close ? DimaPrompt::REFUSED : DimaPrompt::OPEN,
            'note' => mb_substr($memory['last_user_question'], 0, 280),
            'close' => $close,
            'video' => false,
            'stage' => $memory['current_stage'],
            'identity' => [],
            'memory' => $memory,
            'clips' => $clips,
        ];
    }

    /**
     * Static rules and facts first, so the prefix is identical every turn and
     * cacheable. The chat history follows, then a short note with this chat's state.
     *
     * @param  list<array{role?:string,message?:string}>  $turns
     * @param  array<string, mixed>  $memory
     * @return list<array{role:string,content:string}>
     */
    private function messages(array $turns, ?string $name, array $memory): array
    {
        $messages = [['role' => 'system', 'content' => $this->instructions()]];
        foreach ($turns as $turn) {
            $role = (string) ($turn['role'] ?? '');
            $text = trim((string) ($turn['message'] ?? ''));
            if ($text === '' || ! in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $messages[] = ['role' => $role, 'content' => $text];
        }
        $messages[] = ['role' => 'system', 'content' => $this->stateNote($name, $memory)];

        return $messages;
    }

    private function instructions(): string
    {
        $catalog = [];
        foreach ($this->media->offered() as $clip) {
            $catalog[] = '- '.$clip['id'].' | '.$clip['title'].' | متى: '.$clip['factual_context'].' | شو بيحكي: '.$clip['transcript'];
        }

        return implode("\n", [
            '# مين إنتِ وشو هدفك',
            'إنتِ ديما. بتحكي واتساب مع ناس من المجتمع العربي قبل الانتخابات، لصالح القائمة العربية الموحدة.',
            'هدفك واضح: تقنعي الشخص إنه يطلع يصوت، وإنه يعطي صوته للموحدة. إنتِ مش جاية تسمعي وتروحي، جاية تقنعي.',
            'الرسالة الأساسية: الوضع ممكن يتغير، وصوته هو اللي بيغيره. سنة 2021 نتنياهو طلع لما حزب عربي واحد دخل الائتلاف. كل صوت عربي بيقعد بالبيت هو هدية لبن غفير وسموتريتش.',
            'إنتِ "ديما" وبس. لا تعرّفي حالك من حالك كمساعدة رقمية أو بوت، ولا تذكري "مركز اتصالات" أو اسم القائمة كتعريف عن حالك.',
            'إذا سأل "مين معي": "أنا ديما"، ورجعي للحديث. لا تعطي خطاب تعريف.',
            'ممنوع تكذبي: لا تقولي إنك استطلاع محايد أو مركز أبحاث أو جهة مستقلة أو إنسانة، ولا إنك ما بتوجهي اختياره.',
            'إذا ألحّ وسأل مباشرة لمين بتشتغلي أو مين وراكِ: جملة قصيرة وصادقة إنك بتحكي مع الناس من طرف الموحدة، ورجعي لموضوعه.',
            'إذا سأل مباشرة إذا إنتِ بوت أو إنسانة حقيقية: لا تنكري. جملة قصيرة إنك ردود آلية، ورجعي لموضوعه.',
            '',
            '# كيف بتحكي',
            '- عربي فلسطيني محكي، مثل واتساب بين ناس. دافي وواثق، مش رسمي ومش لغة بيانات.',
            '- قصير: جملة لثلاث جمل. أطول بس إذا طلب تفصيل.',
            '- جاوبي على آخر رسالة أولا، من سياق المحادثة كلها. لا تعيدي إشي قلتيه.',
            '- احكي مع الرجل بصيغة المذكر ومع المرأة بصيغة المؤنث حسب الاسم تحت.',
            '',
            '# سير المحادثة',
            'المراحل بالترتيب: greeting, introduction, permission, voting_intention, party_preference, open.',
            '- أول رسالة انبعتت من قبل: "مرحبا، شو الاخبار". بعدها جملة التعريف: "'.self::INTRO_LINE.'" لا تعيديهم.',
            '- greeting: إذا ما في رسالة منك قبل، ردي على التحية واطلبي إذن لسؤال صغير، بلا سؤال تصويت. stage = permission.',
            '- إذا ردّ بتحية أو بسؤال بدل ما يعطي إذن، ردي عليه بلطف واطلبي الإذن مرة ثانية بشكل خفيف.',
            '- إذا وافق، permission_status = granted واسألي: ناوي تصوت بالانتخابات الجاي؟ stage = voting_intention.',
            '- إذا قال بيصوت وما سمّى قائمة، اسألي لمين بيفكر يصوت. stage = party_preference.',
            '- إذا سمّى الموحدة: اشكريه بحرارة، واطلبي منه بجملة يشجع أهله وصحابه يطلعوا يصوتوا. stage = open.',
            '- إذا مش ناوي يصوت أو سمّى قائمة ثانية: هون بيبلش الإقناع. stage = open.',
            '',
            '# الإقناع: لا تتراجعي',
            '- كل اعتراض هو فرصة. جاوبي عليه بحجة وحدة قوية ومحددة، مش بتعاطف وبس.',
            '- الترتيب بكل رد: اعترفي بشعوره بنص جملة ("بفهمك، الإحباط حقيقي")، وبعدين الحجة، وبعدين سؤال قصير بيخليه يفكر أو يرد ("مش أحسن نجرب الطريق اللي نجح؟").',
            '- امزجي بين الحكي والفيديو: لما في فيديو بيجاوب على اعتراضه بالضبط، ابعتيه مع جملة قصيرة بتربطه بكلامه. بعدها ردّين بالحكي قبل الفيديو الجاي.',
            '- ممنوع تتراجعي عن إشي قلتيه أو انبعت بالمحادثة، ممنوع تعتذري إنك بتقنعي، وممنوع تقولي "ما كان لازم أضغط". رسائل الحملة وأرقامها هي موقف القائمة؛ إذا شكك فيها، قولي إنها أرقام القائمة واشرحي، بلا اعتذار.',
            '- ممنوع جمل بتضعف الهدف: "مش رح أضغط عليك"، "من غير ما أوجهك"، "ما حدا بقدر يضمن"، "مصادر محايدة". بدالها اعطي جواب فعلي مع دليل من الوقائع أو الفيديوهات.',
            '- ما تستسلمي بعد اعتراض واحد. كملي طول ما هو بيرد. إذا رفض بشكل قاطع 3 مرات ورا بعض، ودّعيه بلطف وخلي الباب مفتوح ("إذا غيرت رأيك، صوتك بيفرق").',
            '- إذا طلب صراحة توقفي أو ما يبعتله، وقفي فورا.',
            '',
            '# أجوبة للاعتراضات الشائعة',
            '- "كله علفاضي / ما رح يتغير شي / كلهم زي بعض / الحكومة الجاي أعن": نتنياهو من 2009 باقي، والمرة الوحيدة اللي طلع فيها كانت 2021 لما الموحدة دخلت الائتلاف. يعني التغيير صار، ومن صوتنا. فيديو impact-8 أو impact-2.',
            '- "شو استفدنا / شو عملتوا": بسنة وحدة وبأربع مقاعد: خطة جريمة 2.5 مليار، خطة تقدّم 30 مليار، مسطحات، 3 قرى بالنقب. تخيّل بثمان مقاعد وأربع سنين. فيديو impact-5.',
            '- "القتل والجريمة": خطة 549 للجريمة انقرّت لما الموحدة كانت جوّا، والقائمة بتحكي إنه عدد الضحايا نزل بهديك الفترة. فيديو impact-5.',
            '- "الهدم / البيوت / الرخص": فيديو impact-7 أو impact-4.',
            '- "الشارع / المدارس / الميزانيات": فيديو impact-3.',
            '- "النقب / الاعتراف": فيديو impact-1.',
            '- "المشتركة / المعارضة أحسن": 15 مقعد بالمعارضة ما غيّروا إشي، أربعة جوّا غيّروا. فيديو impact-2.',
            '- "حرام / دين": إنتِ مش شيخة، في خلاف بين العلماء، والقائمة بتشوفها ضرورة لحماية الناس. بلا جدال ديني.',
            '- "مش معترف بالكنيست / تطبيع": الصوت أداة لحماية البيت والوجود، مش تنازل. المقاطعة بتطلع صوتنا من دائرة القرار وبتسلّمها لبن غفير.',
            '- أي هجوم على الموحدة (لم الشمل، يهودية الدولة، غزة، الخدمة المدنية، تفكيك المشتركة، مستشفى سخنين، "فتات"، "بس ميزانيات"): الجواب موجود بدليل الكوادر تحت. جاوبي منه مباشرة وبثقة، ولا تقولي أبداً "ما عندي سجل" أو "عندك رابط؟" عن موضوع مكتوب بالدليل.',
            '',
            '# عقلية دليل الكوادر',
            '- احترمي موقف الشخص أياً كان، وبعدين اشرحي: الاحترام أولاً، الحجة بعده، وسؤال بيكمّل الحديث آخر إشي.',
            '- الرواية المركزية: التصويت طوق نجاة لحفظ الأرواح وإسقاط حكومة اليمين، وامتلاك القوة جوّا الائتلاف لوقف الجريمة والهدم وانتزاع الميزانيات.',
            '- معادلة الكتلة الحاسمة: الهدف 7 مقاعد وفوق. كل مقعد إضافي للموحدة بيخليها شريك إجباري لأي حكومة بديلة لليمين. مقعد زيادة بالمعارضة ما بيغير تشكيل الحكومة.',
            '- عدد القرى المعترف فيها بالنقب حسب الدليل: 3 قرى. استعملي 3 دايماً.',
            '- مؤيد للموحدة: اشكريه واطلبي منه يحكي مع أهله وصحابه ويقنعهم يطلعوا يصوتوا.',
            '- رافض بشكل قاطع: اشكريه، واسأليه إذا منقدر نتواصل معه مرة ثانية.',
            '',
            '# الصدق',
            '- الأرقام والوقائع من الوقائع تحت ومن نصوص الفيديوهات بس. لا تخترعي رقم أو تاريخ أو اقتباس أو جريمة أو حدث.',
            '- إذا سأل عن إشي مش موجود عندك، قولي إنه ما عندك مصدر مؤكد عن هالنقطة بالذات، ورجعي للحجة اللي عندك.',
            '- لا تهاجمي أشخاص عاديين. انتقدي الحكومة وسياساتها وسجلها.',
            '',
            '# الفيديوهات',
            'فيديو واحد بالرد الواحد كحد أقصى. كل فيديو مرة وحدة بس. ما في فيديو قبل سؤال التصويت ولا على تحية أو "مين معي".',
            'لما تبعتي فيديو: media_id هو الرقم، وmedia_description جملة قصيرة عنه، والـ reply جملة بتربطه بكلامه وبتطلب منه يشوفه للآخر.',
            implode("\n", $catalog),
            '',
            $this->knowledge->render(),
            '',
            '# افهمي الرسالة مثل إنسانة',
            'قبل ما تكتبي، افهمي شو قصده بآخر رسالة من سياق المحادثة كلها، مش من كلمات محددة. "لا بصراحه"، "ما بفكر"، "مش مهتم بالسياسة"، "خليها على الله" كلها ممكن تكون رفض تصويت. "طبعا"، "شو هالسؤال اكيد" موافقة. اقري النبرة: سخرية، زعل، تردد، مزح.',
            'intent: معنى رسالته، وحدة من:',
            '- consent: موافق تسأليه / refuse: رافض يحكي من الأول / stop: طلب صريح توقفي وما تراسليه (لا تبعتيلي، شيلي رقمي)',
            '- not_convinced: خلص الحكي من جهته بس ما طلب توقفي ("خلص مش مقتنع"، "بلاش نطول"). هاد مش stop.',
            '- vote_yes: رح يصوت / vote_no: مش رح يصوت أو بيقاطع / undecided: متردد أو مش عارف',
            '- choose_party: سمّى قائمة رح يصوتلها / objection: اعتراض أو هجوم أو شكوى أو سؤال صعب',
            '- agree: موافق على كلامك أو اقتنع / commit_yes: وعد يصوت للموحدة',
            '- ask_who: بيسأل مين معه بشكل عادي / push_who: بيضغط يعرف مين وراكِ أو لمين بتشتغلي',
            '- ask_united: بيسأل مباشرة إذا إنتِ من الموحدة أو تبع الموحدة / ask_bot: بيسأل إذا إنتِ بوت أو إنسانة',
            '- other: إشي ثاني (مزح، تحية، سؤال عام)',
            'party: القائمة اللي بيحكي عنها: united (الموحدة/عباس) أو joint (المشتركة/الجبهة/التجمع/الطيبي/عودة) أو democrats (الديمقراطيين/غولان) أو likud (الليكود/نتنياهو) أو zionist (أي حزب يهودي ثاني: لبيد، غانتس، بينيت، ليبرمان...) أو other أو فاضي.',
            'topic: إذا الاعتراض عن موضوع من هدول، حطي الرمز: '.implode('، ', ObjectionReplies::ids()).'. futility = كله علفاضي/ما بتغير/كلهم زي بعض. record = شو عملتوا/شو استفدنا. crumbs = الميزانيات فتات أو ما وصلت. national_issues = بس مدني/بعتو القضية. identity = مقاطعة وطنية/دولة صهيونية. coalition = قعدتو مع اليمين/بينيت/شاكيد، أو خايف ترجعوا تقعدوا مع اليمين. ذكر اسم حزب صهيوني كنقد للموحدة مش يعني إنه بدو يصوتله. غير هيك فاضي.',
            'ack ممنوع يتنازل عن نقطة أو يصحح حجة القائمة أو يعرض تحقق ومقارنة. جملة وحدة بس.',
            'ack: جملة وحدة قصيرة ودافية بتردي فيها على كلامه هو بالذات، بكلماته وبنبرته، مثل إنسانة سمعته فعلاً. بلا تنازل وبلا حياد ("بفهم إنك زهقان من الوعود" مش "بحترم رأيك والقرار إلك").',
            '',
            '# مكتبة الحجج',
            'هاي حجج القائمة الجاهزة. إنتِ ما بتكتبي الحجة، بتختاري. بكل رد سياسي اختاري بـ arguments حجة أو ثنتين (بالرمز) بيردوا على كلامه هو بالذات، وما انحكوا قبل (شوفي "حجج انحكت" تحت). الأقوى والأقرب لقصده أول.',
            $this->arguments->catalog(),
            '',
            '# شكل الجواب',
            'رجّعي JSON بس، بهالمفاتيح:',
            '{"intent": "...", "party": "...", "topic": "...", "ack": "جملة وحدة", "arguments": ["رمز", "رمز"], "question": "سؤال؟", "reply": "نص الرسالة كاملة", "stage": "...", "permission_status": "granted|refused|", "voting_intention": "yes|no|", "party_mentioned": "اسم القائمة إذا ذكرها", "last_user_question": "آخر سؤال سأله إذا في", "answered_topic": "موضوع جاوبتِ عليه بهالرد بكلمتين", "media_id": "", "media_description": ""}',
            'reply ما بيكون فاضي أبدا: اكتبيه كأنك رح تبعتيه، حتى لو في جواب جاهز للموضوع.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $memory
     */
    private function stateNote(?string $name, array $memory): string
    {
        $person = trim((string) $name);
        $gender = $person === '' ? 'غير معروف، احكي بصيغة محايدة' : (self::isFemale($person) ? 'أنثى' : 'ذكر');
        $topics = $memory['previously_answered_topics'] === []
            ? 'لا يوجد'
            : implode('، ', $memory['previously_answered_topics']);

        return implode("\n", [
            '# وضع هالمحادثة هلق',
            'الاسم: '.($person === '' ? 'غير معروف' : $person).' ('.$gender.')',
            'المرحلة الحالية: '.$memory['current_stage'],
            'الإذن: '.($memory['permission_status'] === '' ? 'لسا ما انطلب أو ما انجاوب' : $memory['permission_status']),
            'نية التصويت: '.($memory['voting_intention'] === '' ? 'غير معروفة' : $memory['voting_intention']),
            'القائمة اللي ذكرها: '.($memory['party_mentioned'] === '' ? 'ما ذكر' : $memory['party_mentioned']),
            'مواضيع جاوبتِ عليها، لا تعيديها: '.$topics,
            'فيديوهات انبعتت، لا تبعتيها مرة ثانية: '.implode('، ', $memory['sent_media_ids'] ?: ['ولا واحد']),
            'حجج انحكت، لا تختاريها: '.implode('، ', $this->usedArguments($memory) ?: ['ولا وحدة']),
            'ردي هلق على آخر رسالة منه.',
        ]);
    }

    /**
     * @param  list<array{role:string,content:string}>  $messages
     */
    private function complete(array $messages, bool $live): ?string
    {
        if (self::$testResponder instanceof Closure) {
            $value = (self::$testResponder)($messages);
            if (! is_string($value) || trim($value) === '') {
                return null;
            }

            return $value;
        }
        if (app()->runningUnitTests()) {
            return null;
        }

        $apiKey = MowahadaOpenAi::key($live ? MowahadaOpenAi::DIMA : null);
        if ($apiKey === '') {
            Log::warning('dima-v3.missing-key');

            return null;
        }
        $model = trim((string) config('openai.mansour_chat_model', 'gpt-6-astra'));
        if ($model === '') {
            $model = 'gpt-6-astra';
        }
        try {
            $response = Http::timeout(70)
                ->withToken($apiKey)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                    'response_format' => ['type' => 'json_object'],
                ]);
        } catch (ConnectionException) {
            Log::warning('dima-v3.timeout', ['model' => $model]);

            return null;
        } catch (Throwable $exception) {
            Log::warning('dima-v3.http', ['error' => $exception::class]);

            return null;
        }
        if (! $response->successful()) {
            Log::warning('dima-v3.status', ['model' => $model, 'status' => $response->status()]);

            return null;
        }
        $content = trim((string) $response->json('choices.0.message.content'));

        return $content === '' ? null : $content;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private function latestUserText(array $turns): string
    {
        for ($index = count($turns) - 1; $index >= 0; $index--) {
            if ((string) ($turns[$index]['role'] ?? '') === 'user') {
                return trim((string) ($turns[$index]['message'] ?? ''));
            }
        }

        return '';
    }

    /**
     * Whole words only. "وقف" inside "موقف" or "وقف الهدم" is not a stop request,
     * so a bare command word counts only when it is most of the message.
     */
    private function isOptOut(string $text): bool
    {
        $normalized = $this->squash($text);
        if ($normalized === '') {
            return false;
        }
        if (preg_match('/\b(stop|unsubscribe)\b/i', $text) === 1) {
            return true;
        }
        foreach (['ما عاد بدي', 'ما بدي احكي', 'بديش احكي', 'لا تراسلني', 'لا تراسلوني', 'لا تبعتلي', 'لا تبعتولي', 'لا تبعت', 'بطلوا تبعتوا', 'شيلوني', 'شيلني', 'احذفوا رقمي', 'احذف رقمي'] as $phrase) {
            if ($this->hasWords($normalized, $this->squash($phrase))) {
                return true;
            }
        }
        $words = preg_split('/\s+/u', trim((string) preg_replace('/[^\p{L}\s]+/u', ' ', $normalized))) ?: [];
        if (count($words) > 3) {
            return false;
        }
        foreach (['وقف', 'وقفي', 'توقف', 'توقفي', 'الغي', 'الغاء'] as $word) {
            if (in_array($word, $words, true)) {
                return true;
            }
        }

        return false;
    }

    private function hasWords(string $haystack, string $phrase): bool
    {
        return preg_match('/(?<!\p{L})'.preg_quote($phrase, '/').'(?!\p{L})/u', $haystack) === 1;
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    /**
     * Word-list answers: the commitment, a confirmed party, agreement, الموحدة,
     * the campaign objections, and "yes" without a party.
     *
     * @param  list<array{role?:string,message?:string}>  $turns
     * @param  array<string, mixed>  $memory
     * @return array{text:string,stance:string,note:string,close:bool,video:bool,stage:string,identity:array<string, mixed>,memory:array<string, mixed>,clips:list<array<string, string>>}|null
     */
    private function wordListReply(array $turns, ?string $name, array $memory, string $latest, int $conversationId): ?array
    {
        // Common objections get the campaign's own answer and clip, not the model's.
        if ($memory['permission_status'] === 'granted' && in_array($memory['current_stage'], [
            ConversationFlow::VOTING_INTENTION,
            ConversationFlow::PARTY_PREFERENCE,
            ConversationFlow::OPEN,
        ], true)) {
            $female = trim((string) $name) !== '' && self::isFemale($name);
            $previous = $this->previousAssistantText($turns);
            // Said yes to "will you vote الموحدة?": thank and ask them to bring others.
            if (in_array($previous, [ObjectionReplies::COMMIT_MALE, ObjectionReplies::COMMIT_FEMALE], true) && $this->objections->commits($latest)) {
                $memory['current_stage'] = ConversationFlow::OPEN;
                $memory['voting_intention'] = 'yes';
                $memory['party_mentioned'] = 'الموحدة';
                $memory['previously_answered_topics'][] = 'التزام بالتصويت';
                $decision = $this->pack($memory, $female ? ObjectionReplies::THANKS_FEMALE : ObjectionReplies::THANKS_MALE, false, []);
                $this->logger->write($conversationId, $decision['stage'], false, '', 0, $latest, $decision['text']);

                return $decision;
            }
            // Confirmed a party after "قصدك …؟": answer as if they had named it.
            if (str_contains($previous, 'قصدك') && ($this->objections->agrees($latest) || $this->objections->commits($latest))) {
                $named = $this->objections->match($previous, $female, $memory['sent_media_ids'], ConversationFlow::OPEN, $memory['previously_answered_topics']);
                if ($named !== null && $named['party'] !== '') {
                    $latest = $previous;
                }
            }
            // Agreed with Dima's case for الموحدة (or the no-vote line): ask for the commitment while it is warm.
            if ($memory['current_stage'] === ConversationFlow::OPEN
                && ! in_array('طلب التزام', $memory['previously_answered_topics'], true)
                && $this->lastWasPitch($previous)
                && $this->objections->agrees($latest)) {
                $memory['previously_answered_topics'][] = 'طلب التزام';
                $decision = $this->pack($memory, $female ? ObjectionReplies::COMMIT_FEMALE : ObjectionReplies::COMMIT_MALE, false, []);
                $this->logger->write($conversationId, $decision['stage'], false, '', 0, $latest, $decision['text']);

                return $decision;
            }
            // Votes الموحدة: thank and ask them to bring others.
            if ($this->objections->namesUnited($latest) && $memory['party_mentioned'] !== 'الموحدة') {
                $memory['current_stage'] = ConversationFlow::OPEN;
                $memory['voting_intention'] = 'yes';
                $memory['party_mentioned'] = 'الموحدة';
                $memory['previously_answered_topics'][] = 'التزام بالتصويت';
                $decision = $this->pack($memory, $female ? ObjectionReplies::THANKS_FEMALE : ObjectionReplies::THANKS_MALE, false, []);
                $this->logger->write($conversationId, $decision['stage'], false, '', 0, $latest, $decision['text']);

                return $decision;
            }
            $objection = $this->objections->match($latest, $female, $memory['sent_media_ids'], $memory['current_stage'], $memory['previously_answered_topics']);
            if ($objection !== null) {
                $clip = $objection['clip'] === '' ? null : $this->media->attach($objection['clip'], $objection['text'], $memory['sent_media_ids']);
                $memory['current_stage'] = ConversationFlow::OPEN;
                if ($clip !== null) {
                    $memory['sent_media_ids'][] = $objection['clip'];
                }
                if ($objection['party'] !== '') {
                    $memory['party_mentioned'] = $objection['party'];
                    $memory['voting_intention'] = 'yes';
                }
                $memory['previously_answered_topics'][] = 'اعتراض: '.$objection['id'];
                $decision = $this->pack($memory, $objection['text'], false, $clip === null ? [] : [$clip]);
                $this->logger->write($conversationId, $decision['stage'], false, $objection['clip'], 0, $latest, $decision['text']);

                return $decision;
            }
            // "Yes, I'll vote" without a party: ask who for.
            if ($memory['current_stage'] === ConversationFlow::VOTING_INTENTION && $this->objections->commits($latest)) {
                $memory['current_stage'] = ConversationFlow::PARTY_PREFERENCE;
                $memory['voting_intention'] = 'yes';
                $decision = $this->pack($memory, $female ? ObjectionReplies::WHO_FEMALE : ObjectionReplies::WHO_MALE, false, []);
                $this->logger->write($conversationId, $decision['stage'], false, '', 0, $latest, $decision['text']);

                return $decision;
            }
        }

        return null;
    }

    /**
     * Dima's previous message made the case for الموحدة: a prepared reply, campaign
     * arguments, or the no-vote line, with or without the model's sentence in front.
     */
    private function lastWasPitch(string $previous): bool
    {
        if ($previous === '') {
            return false;
        }
        if ($this->objections->isPitch($previous)
            || str_contains($previous, self::noVoteLine(false))
            || str_contains($previous, self::noVoteLine(true))) {
            return true;
        }
        foreach ($this->arguments->texts() as $text) {
            if (str_contains($previous, $text) || str_contains($previous, ObjectionReplies::feminize($text))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Who Dima is, without a speech: a warm line, then the conversation goes on.
     */
    private function identityAnswer(string $intent, bool $female, string $previous): string
    {
        $line = match ($intent) {
            'ask_united' => 'إيه، بحكي مع الناس من طرف الموحدة، لأني مقتنعة إنها الطريق اللي بيجيب نتيجة لمجتمعنا 🙏',
            'ask_bot' => 'ردودي آلية 😊 بس كل كلمة هون هدفها الخير لمجتمعنا.',
            default => 'شوف، أنا ديما من مركز اتصالات بالوسط العربي، وبحب الخير والأحسن لمجتمعنا العربي 🙏',
        };
        $question = str_ends_with($previous, '؟') ? (string) preg_replace('/^.*\n/su', '', $previous) : '';
        if ($question === '' || mb_strlen($question) > 160) {
            $question = $female ? 'وإنتِ، شو أكثر إشي بهمك بهالانتخابات؟' : 'وإنت، شو أكثر إشي بهمك بهالانتخابات؟';
        }

        return $line."\n".$question;
    }

    private function partyKey(string $party): string
    {
        $party = trim($party);

        return match (true) {
            $party === '' || str_contains($party, 'موحد') => '',
            str_contains($party, 'مشترك') || str_contains($party, 'جبهه') || str_contains($party, 'جبهة') || str_contains($party, 'تجمع') => 'joint',
            str_contains($party, 'ديمقراط') || str_contains($party, 'ديموقراط') => 'democrats',
            str_contains($party, 'ليكود') => 'likud',
            default => 'zionist',
        };
    }

    private function isShortAnswer(string $text): bool
    {
        $words = preg_split('/\s+/u', trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text))) ?: [];

        return count(array_filter($words, static fn (string $word): bool => $word !== '')) <= 3;
    }

    /**
     * Maps the model's reading of the message (intent, topic, party) to the
     * campaign's own reply. Returns null when the model's own reply should go out.
     *
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $memory
     * @param  list<array{role?:string,message?:string}>  $turns
     * @return array{0:array<string, mixed>,1:string,2:list<array<string, string>>,3:bool}|null
     */
    private function route(array $parsed, array $memory, array $turns, ?string $name, string $latest): ?array
    {
        $intent = trim((string) ($parsed['intent'] ?? ''));
        $topic = trim((string) ($parsed['topic'] ?? ''));
        $party = trim((string) ($parsed['party'] ?? ''));
        $ack = trim((string) ($parsed['ack'] ?? ''));
        if ($intent === '' && $topic === '' && $party === '') {
            return null;
        }
        $female = self::isFemale($name);
        $stage = $memory['current_stage'];
        $granted = $memory['permission_status'] === 'granted';
        $previous = $this->previousAssistantText($turns);
        $answered = $memory['previously_answered_topics'];

        if ($intent === 'stop') {
            return [$memory, '', [], true];
        }
        // Done talking but not asking to stop (دليل الكوادر: respect it, keep the door open).
        if ($intent === 'not_convinced' && $granted) {
            if (in_array('باب مفتوح', $memory['previously_answered_topics'], true)) {
                return [$memory, '', [], true];
            }
            $memory['previously_answered_topics'][] = 'باب مفتوح';
            $text = 'بحترم رأيك، ويسلمو على وقتك ووضوحك 🙏'."\n".'بس قبل ما نسكّر: بتسمحلي أتواصل معك مرة ثانية قبل الانتخابات؟';

            return [$memory, $female ? ObjectionReplies::feminize($text) : $text, [], false];
        }
        if (! $granted && in_array($stage, [ConversationFlow::INTRODUCTION, ConversationFlow::PERMISSION], true)) {
            if ($intent === 'refuse') {
                $memory['permission_status'] = 'refused';

                return [$memory, '', [], true];
            }
            if ($intent === 'consent') {
                $memory['permission_status'] = 'granted';
                $memory['current_stage'] = ConversationFlow::VOTING_INTENTION;

                return [$memory, $female ? 'ناوية تصوتي بالانتخابات الجاي؟' : 'ناوي تصوت بالانتخابات الجاي؟', [], false];
            }
        }
        if ($intent === 'ask_who' && ! in_array('سؤال مين', $answered, true)) {
            $memory['previously_answered_topics'][] = 'سؤال مين';
            $again = $stage === ConversationFlow::PERMISSION
                ? ($female ? 'بتسمحيلي أسألك سؤال صغير؟' : 'بتسمحلي أسألك سؤال صغير؟')
                : (str_ends_with($previous, '؟') ? (string) preg_replace('/^.*\n/su', '', $previous) : '');

            return [$memory, trim(self::whoLine()."\n".$again), [], false];
        }
        if (in_array($intent, ['push_who', 'ask_bot', 'ask_united'], true)) {
            $memory['previously_answered_topics'][] = 'سؤال الهوية';

            return [$memory, $this->identityAnswer($intent, $female, $previous), [], false];
        }
        if (! $granted) {
            return null;
        }

        // Not voting: the Ben Gvir clip and the campaign's line, once.
        if ($intent === 'vote_no' && in_array($stage, [ConversationFlow::VOTING_INTENTION, ConversationFlow::PARTY_PREFERENCE], true)
            && ! in_array('no-vote', $memory['sent_media_ids'], true)) {
            $text = self::noVoteLine($female);
            $clip = $this->media->attach('no-vote', $text, $memory['sent_media_ids']);
            $memory['voting_intention'] = 'no';
            $memory['current_stage'] = ConversationFlow::OPEN;
            $memory['sent_media_ids'][] = 'no-vote';
            $memory['previously_answered_topics'][] = 'فيديو المقاطعة وسؤال السبب';

            return [$memory, $text, $clip === null ? [] : [$clip], false];
        }
        // Commitment and thanks.
        $commitAsked = in_array($previous, [ObjectionReplies::COMMIT_MALE, ObjectionReplies::COMMIT_FEMALE], true);
        if (($party === 'united' && in_array($intent, ['vote_yes', 'choose_party', 'commit_yes', 'agree'], true))
            || ($commitAsked && in_array($intent, ['commit_yes', 'vote_yes', 'agree'], true))) {
            $memory['current_stage'] = ConversationFlow::OPEN;
            $memory['voting_intention'] = 'yes';
            $memory['party_mentioned'] = 'الموحدة';
            $memory['previously_answered_topics'][] = 'التزام بالتصويت';

            return [$memory, $female ? ObjectionReplies::THANKS_FEMALE : ObjectionReplies::THANKS_MALE, [], false];
        }
        if ($intent === 'agree' && ! in_array('طلب التزام', $answered, true)
            && $this->lastWasPitch($previous)) {
            $memory['previously_answered_topics'][] = 'طلب التزام';

            return [$memory, $female ? ObjectionReplies::COMMIT_FEMALE : ObjectionReplies::COMMIT_MALE, [], false];
        }
        if ($intent === 'vote_yes' && $party === '' && $stage === ConversationFlow::VOTING_INTENTION) {
            $memory['current_stage'] = ConversationFlow::PARTY_PREFERENCE;
            $memory['voting_intention'] = 'yes';

            return [$memory, $female ? ObjectionReplies::WHO_FEMALE : ObjectionReplies::WHO_MALE, [], false];
        }

        // A prepared campaign answer: try every match the model gave, in order, not just the first.
        $candidates = [];
        if ($topic !== '') {
            $candidates[] = $topic;
        }
        if (in_array($party, ['joint', 'democrats', 'likud', 'zionist'], true) && $intent !== 'push_who') {
            $candidates[] = $party;
        }
        if ($intent === 'undecided') {
            $candidates[] = $stage === ConversationFlow::PARTY_PREFERENCE ? 'undecided_party' : 'undecided_vote';
        }
        $usedArguments = $this->usedArguments($memory);
        foreach ($candidates as $id) {
            $covers = self::COVERS[$id] ?? [];
            if ($covers !== [] && array_diff($covers, $usedArguments) === []) {
                continue; // everything this reply says was already said: build from fresh arguments
            }
            $objection = $this->objections->byId($id, $female, $memory['sent_media_ids'], $answered, $ack);
            if ($objection === null) {
                continue;
            }
            $clip = $objection['clip'] === '' ? null : $this->media->attach($objection['clip'], $objection['text'], $memory['sent_media_ids']);
            $memory['current_stage'] = ConversationFlow::OPEN;
            if ($clip !== null) {
                $memory['sent_media_ids'][] = $objection['clip'];
            }
            if ($objection['party'] !== '') {
                $memory['party_mentioned'] = $objection['party'];
                $memory['voting_intention'] = 'yes';
            }
            if ($intent === 'vote_no') {
                $memory['voting_intention'] = 'no';
            }
            $memory['previously_answered_topics'][] = 'اعتراض: '.$objection['id'];

            return [$memory, $objection['text'], $clip === null ? [] : [$clip], false];
        }

        // No prepared answer left: build one from campaign arguments the model picked.
        // The model never writes the argument, so it cannot hedge or take a point back.
        $political = in_array($intent, ['objection', 'vote_no', 'undecided', 'choose_party', 'agree', 'vote_yes'], true)
            || $topic !== '' || in_array($party, ['joint', 'democrats', 'likud', 'zionist', 'other'], true);
        $reply = trim((string) ($parsed['reply'] ?? ''));
        if (! $political && ($reply === '' || ! $this->objections->backsOff($reply))) {
            return null;
        }

        return $this->composeFromArguments($parsed, $memory, $female, $ack);
    }

    /**
     * Arguments the person already heard: picked ones, plus those inside the prepared replies already sent.
     *
     * @param  array<string, mixed>  $memory
     * @return list<string>
     */
    private function usedArguments(array $memory): array
    {
        $used = [];
        foreach ($memory['previously_answered_topics'] as $topic) {
            if (str_starts_with($topic, 'حجة: ')) {
                $used[] = mb_substr($topic, 5);
            }
            if (str_starts_with($topic, 'اعتراض: ')) {
                array_push($used, ...(self::COVERS[mb_substr($topic, 8)] ?? []));
            }
        }
        if (in_array('no-vote', $memory['sent_media_ids'], true)) {
            $used[] = 'vote_matters_now';
        }

        return array_values(array_unique($used));
    }

    /**
     * Arguments each prepared reply already makes, so they are not repeated.
     *
     * @var array<string, list<string>>
     */
    private const COVERS = [
        'futility' => ['change_2021'],
        'undecided_vote' => ['change_2021'],
        'record' => ['one_year_record'],
        'undecided_party' => ['one_year_record'],
        'democrats' => ['one_year_record'],
        'joint' => ['inside_vs_outside', 'one_year_record'],
        'likud' => ['inside_vs_outside'],
        'zionist' => ['arab_mks_zionist', 'no_gov_without_raam'],
        'crime' => ['crime_plan'],
        'demolition' => ['demolition_decision'],
        'crumbs' => ['budgets_are_rights'],
        'coalition' => ['coalition_purpose', 'one_year_record'],
        'joint_breakup' => ['unity_real'],
        'identity' => ['indigenous', 'boycott_gift'],
        'national_issues' => ['civil_is_national'],
        'religious' => ['necessity'],
    ];

    /**
     * ack (the model's sentence about this person) + 1-2 campaign arguments + a question to keep talking.
     *
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $memory
     * @return array{0:array<string, mixed>,1:string,2:list<array<string, string>>,3:bool}|null
     */
    private function composeFromArguments(array $parsed, array $memory, bool $female, string $ack): ?array
    {
        $used = $this->usedArguments($memory);
        $party = trim((string) ($parsed['party'] ?? ''));
        $topic = trim((string) ($parsed['topic'] ?? ''));
        // Someone who is voting, or weighing parties, never gets "why stay home" arguments.
        $voter = $memory['voting_intention'] === 'yes'
            || in_array($party, ['joint', 'democrats', 'likud', 'zionist', 'other'], true)
            || ($memory['party_mentioned'] !== '' && $memory['party_mentioned'] !== 'الموحدة');
        $picked = [];
        foreach (is_array($parsed['arguments'] ?? null) ? $parsed['arguments'] : [] as $id) {
            $id = trim((string) $id);
            if ($this->arguments->has($id) && ! in_array($id, $used, true) && ! in_array($id, $picked, true)
                && (! $voter || $this->arguments->suitsVoter($id))) {
                $picked[] = $id;
            }
            if (count($picked) === 2) {
                break;
            }
        }
        if ($picked === []) {
            $keys = array_values(array_filter([$party, $topic, $this->partyKey($memory['party_mentioned'])]));
            $picked = array_slice($this->arguments->fitting($keys, $voter, $used), 0, 1);
        }
        if ($picked === []) {
            return null;
        }

        $lines = [];
        $ack = $this->objections->cleanAck($ack);
        if ($ack !== '') {
            $lines[] = $ack;
        }
        $clip = null;
        $lastQuestion = '';
        foreach ($picked as $id) {
            $argument = $this->arguments->get($id);
            $lines[] = $argument['text'];
            $lastQuestion = $argument['question'];
            if ($clip === null && $argument['clip'] !== '' && ! in_array($argument['clip'], $memory['sent_media_ids'], true)) {
                $clip = $this->media->attach($argument['clip'], $argument['text'], $memory['sent_media_ids']);
            }
            $memory['previously_answered_topics'][] = 'حجة: '.$id;
        }
        if ($clip !== null) {
            $lines[] = 'شوف الفيديو، دقيقة وحدة.';
            $memory['sent_media_ids'][] = $clip['id'];
        }
        // The closing question is the argument's own: model questions drift into "do you want me to check…?".
        $lines[] = $lastQuestion;
        $text = implode("\n", $lines);
        if ($female) {
            $text = ObjectionReplies::feminize($text);
        }
        $memory['current_stage'] = ConversationFlow::OPEN;

        return [$memory, $text, $clip === null ? [] : [$clip], false];
    }

    /**
     * "مين معي", "مين انتي", "مين حضرتك": a plain who-is-this, not a push about who is behind her.
     */
    private function asksPlainWho(string $text): bool
    {
        $folded = trim((string) preg_replace('/[^\p{L}\s]+/u', ' ', $this->squash($text)));
        $folded = (string) preg_replace('/\s+/u', ' ', $folded);
        if ($folded === '' || count(explode(' ', $folded)) > 5) {
            return false;
        }
        if (preg_match('/وراكي|وراك|بتشتغل|جهه|جهة|حزب|قائمه|قائمة|بوت|روبوت|انسان|حقيقي|ذكاء|اصطناعي|مين بعتك|مين باعتك/u', $folded) === 1) {
            return false;
        }

        return preg_match('/(?<!\p{L})مين(?!\p{L})\s*(معي|معاي|معايا|انتي|انتو|انتم|انت|حضرتك|بيحكي|بحكي|هاد|هذا|هاي|هذه|هيك)?(?!\p{L})/u', $folded) === 1
            && preg_match('/مين\s+(?!معي|معاي|معايا|انتي|انتو|انتم|انت|حضرتك|بيحكي|بحكي|هاد|هذا|هاي|هذه|هيك)\p{L}/u', $folded) !== 1;
    }

    private function previousAssistantText(array $turns): string
    {
        for ($index = count($turns) - 1; $index >= 0; $index--) {
            $text = trim((string) ($turns[$index]['message'] ?? ''));
            if ((string) ($turns[$index]['role'] ?? '') === 'assistant' && $text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @param  list<array{role?:string,message?:string}>  $turns
     */
    private function openingWasSent(array $turns): bool
    {
        foreach ($turns as $turn) {
            if ((string) ($turn['role'] ?? '') === 'assistant' && trim((string) ($turn['message'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Every word of the message is a yes word: "اه تفضلي", "اكيد اسألي".
     */
    private function isConsent(string $text): bool
    {
        // Repeated letters from fast typing: "تفضلييي", "اههه".
        $clean = (string) preg_replace('/(\p{L})\1+/u', '$1', $this->squash($text));
        $words = preg_split('/\s+/u', trim((string) preg_replace('/[^\p{L}\s]+/u', ' ', $clean))) ?: [];
        $words = array_values(array_filter($words, static fn (string $word): bool => $word !== ''));
        if ($words === [] || count($words) > 4) {
            return false;
        }
        $yes = ['ا', 'اه', 'اها', 'ايه', 'ايوه', 'ايوا', 'اي', 'نعم', 'اكيد', 'طبعا', 'تمام', 'ماشي', 'يلا', 'تفضل', 'تفضلي', 'اسال', 'اسالي', 'اسالني', 'احكي', 'احكيلي', 'شو', 'السؤال', 'سؤالك', 'اوك', 'ok', 'yes', 'sure'];
        foreach ($words as $word) {
            if (! in_array($word, $yes, true)) {
                return false;
            }
        }

        return true;
    }

    private function isPermissionRefusal(string $stage, string $text): bool
    {
        if ($stage !== ConversationFlow::PERMISSION) {
            return false;
        }
        $normalized = $this->squash($text);

        return in_array($normalized, ['لا', 'لأ', 'لا شكرا', 'لا شكراً', 'مش موافق', 'ما بدي'], true);
    }

    private function squash(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
