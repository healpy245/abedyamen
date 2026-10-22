<?php

declare(strict_types=1);

namespace App\Services\AiChatbot\DimaV3;

/**
 * Code-versioned clip catalog. The model picks a clip by id; the engine
 * only attaches ids listed here, each once per chat.
 */
class MediaLibrary
{
    /**
     * Each clip: what it says (transcribed from the file) and when it fits.
     * All are campaign ads from the "احسبها صح، بس موحدة" series except no-vote.
     *
     * @var list<array{id:string,title:string,transcript:string,factual_context:string,category:string}>
     */
    private const CLIPS = [
        [
            'id' => 'impact-1',
            'title' => 'قرى النقب والاعتراف',
            'transcript' => 'عشرات السنين قرى بالنقب بدون اعتراف، بدون مي وبدون كهربا. مظاهرات وبيانات وما تغيّر شي. سنة 2021 دخلت الموحدة الحكومة واعترفت الحكومة بأربع قرى. مش بالصوت العالي، بالتوقيع على اتفاق ائتلاف. اللي جاب الاعتراف لأربعة بيجيبه لغيرهم. بس من جوّا.',
            'factual_context' => 'لما الحكي عن النقب، القرى غير المعترف فيها، المي والكهربا، أو إنه المظاهرات والبيانات ما بتغير.',
            'category' => 'negev',
        ],
        [
            'id' => 'impact-2',
            'title' => 'جربنا كل الطرق',
            'transcript' => 'جربنا ما نصوت، نتنياهو بقي. جربنا 15 عضو بالمعارضة، نتنياهو بقي. جربنا التوصية على غانتس، راح قعد مع نتنياهو. وسنة 2021 جربنا الائتلاف، نتنياهو طلع. أربع طرق، وحدة نجحت، وحزب واحد مشّاها.',
            'factual_context' => 'لما يقول كله علفاضي، جربنا قبل، المقاطعة أحسن، أو بيفضّل المشتركة والمعارضة.',
            'category' => 'futility',
        ],
        [
            'id' => 'impact-3',
            'title' => 'الشارع والمدرسة والميزانية',
            'transcript' => 'الشارع اللي مكسّر ببلدك والمدرسة المزدحمة مش ناقصهم شعارات، ناقصهم ميزانية. والميزانية بتوزعها الحكومة. لما الموحدة كانت جوّا، خطة تقدّم 30 مليار لبلداتنا. لما طلعت، الحكومة سحبت من ميزانيتها. الفرق مين قاعد لما تتوزع الميزانيات.',
            'factual_context' => 'لما الحكي عن البلد، الشوارع، المدارس، الخدمات، أو الميزانيات.',
            'category' => 'budgets',
        ],
        [
            'id' => 'impact-4',
            'title' => 'ابنك بده يبني',
            'transcript' => 'ابنك بده يتجوز ويبني ببلده، ما في قسيمة وما في مسطح. مين بقرر مسطح البلد؟ مش بس البلدية لحالها، الحكومة. لما الموحدة كانت جوّا الحكومة توسعت مسطحات بعدة بلدان بسنة وحدة. مسطح بلدك بيتقرر على طاولة الحكومة، واللي مش قاعد عليها بيطالب وبيستنى.',
            'factual_context' => 'لما الحكي عن السكن، الأرض، القسيمة، المسطح، أو شباب بدهم يبنوا.',
            'category' => 'housing',
        ],
        [
            'id' => 'impact-5',
            'title' => 'شو حققت الموحدة بسنة وحدة',
            'transcript' => 'شو حققت الموحدة بحكومة التغيير بسنة وحدة؟ خطة لمحاربة العنف والجريمة بميزانية 2.5 مليار، ولأول مرة من سنين عدد الضحايا نزل. خطة تقدّم 30 مليار لمجتمعنا: مدارس، شوارع، مواصلات. توسيع مسطحات بعدة بلدات، واعتراف بأربع قرى بالنقب. هاد بسنة وحدة وبأربع مقاعد. احسب شو بتحقق بثمان مقاعد وبأربع سنين.',
            'factual_context' => 'لما يسأل شو عملتوا أو شو استفدنا، أو الحكي عن الجريمة والعنف والقتل.',
            'category' => 'record',
        ],
        [
            'id' => 'impact-7',
            'title' => 'الهدم بيتوقف بقرار',
            'transcript' => 'أمر الهدم بيطلع من الحكومة، واللي بيوقفه كمان الحكومة. البيت بينبنى بدون رخصة لأنه ما في مسطح، والمسطح بتقرره الحكومة. يعني الحل مش بيان استنكار بعد الهدم، الحل قرار قبل الهدم، والقرار بينوخذ جوّا الائتلاف.',
            'factual_context' => 'لما الحكي عن هدم البيوت، الرخص، أو قانون كامينتس.',
            'category' => 'demolition',
        ],
        [
            'id' => 'impact-8',
            'title' => 'المرة الوحيدة اللي نتنياهو طلع',
            'transcript' => 'من سنة 2009 نتنياهو رئيس حكومة. انتخابات ورا انتخابات وهو باقي. مرة وحدة طلع من الحكومة، سنة 2021. شو تغير يومها؟ حزب عربي واحد دخل الائتلاف. مش شعار ومش خطاب، دخول على ائتلاف، واللي عملها مرة بيعملها كمان مرة.',
            'factual_context' => 'لما يقول ما رح يتغير شي، كلهم زي بعض، الحكومة الجاي أعن، أو صوتي ما بيأثر.',
            'category' => 'futility',
        ],
        [
            'id' => 'no-vote',
            'title' => 'بن غفير: أنا صاحب البيت هون',
            'transcript' => 'مقطع بالعبري: بن غفير بيصرخ على نائب عربي: أنا صاحب البيت هون، وبهالوتيرة رح نطردك من هون، سكّر تمك، إنت أول واحد رح أطرده.',
            'factual_context' => 'لما الشخص قال إنه مش ناوي يصوت أو بدو يقاطع. بيوري شو يعني نعيش كمان 4 سنين تحت هالحكومة.',
            'category' => 'turnout',
        ],
    ];

    /**
     * @return list<array{
     *     id:string,
     *     title:string,
     *     source:string,
     *     published_on:string,
     *     transcript:string,
     *     factual_context:string,
     *     category:string,
     *     url:string,
     *     disk:string,
     *     path:string,
     *     name:string,
     *     description:string
     * }>
     */
    public function all(): array
    {
        $clips = [self::fixture()];
        foreach (self::CLIPS as $clip) {
            $file = $clip['id'] === 'no-vote' ? 'dima-no-vote-bengvir.mp4' : 'dima-'.$clip['id'].'.mp4';
            $clips[] = [
                'id' => $clip['id'],
                'title' => $clip['title'],
                'source' => 'القائمة العربية الموحدة',
                'published_on' => '',
                'transcript' => $clip['transcript'],
                'factual_context' => $clip['factual_context'],
                'category' => $clip['category'],
                'url' => '',
                'disk' => 'public',
                'path' => 'videos/'.$file,
                'name' => $file,
                'description' => $clip['title'],
            ];
        }

        return $clips;
    }

    /**
     * Clips the model is shown. The test fixture stays out of the prompt.
     *
     * @return list<array<string, string>>
     */
    public function offered(): array
    {
        return array_values(array_filter($this->all(), static fn (array $clip): bool => $clip['category'] !== 'test'));
    }

    /**
     * @param  list<string>  $sentIds
     * @return array{id:string,disk:string,path:string,name:string,url:string,description:string}|null
     */
    public function attach(string $id, string $description, array $sentIds): ?array
    {
        $description = trim($description);
        if ($description === '' || in_array($id, $sentIds, true)) {
            return null;
        }
        foreach ($this->all() as $clip) {
            if ($clip['id'] !== $id) {
                continue;
            }

            return [
                'id' => $clip['id'],
                'disk' => $clip['disk'],
                'path' => $clip['path'],
                'name' => $clip['name'],
                'url' => $clip['url'],
                'description' => $description,
            ];
        }

        return null;
    }

    /**
     * @return array{
     *     id:string,
     *     title:string,
     *     source:string,
     *     published_on:string,
     *     transcript:string,
     *     factual_context:string,
     *     category:string,
     *     url:string,
     *     disk:string,
     *     path:string,
     *     name:string,
     *     description:string
     * }
     */
    public static function fixture(): array
    {
        return [
            'id' => 'fixture-clip',
            'title' => 'مقطع اختبار',
            'source' => 'اختبار',
            'published_on' => '2026-01-01',
            'transcript' => 'هذا نص تجريبي وليس ادعاء سياسيا.',
            'factual_context' => 'هذا القيد للاختبار فقط وليس حقيقة سياسية.',
            'category' => 'test',
            'url' => 'https://example.test/dima-v3/fixture-clip.mp4',
            'disk' => 'public',
            'path' => 'dima-v3/fixtures/sample.mp4',
            'name' => 'sample.mp4',
            'description' => 'مقطع اختبار يشرح آلية الإرفاق فقط.',
        ];
    }
}
