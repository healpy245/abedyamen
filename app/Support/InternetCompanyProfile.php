<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AiChatbot\ChatbotInstance;

/**
 * Brand + geography for Malan-family internet bots (Malan, Speedcom).
 * Campaigns and CRM tools stay shared; only display copy and default city change.
 */
final class InternetCompanyProfile
{
    public const SLUG_MALAN = 'malan';

    public const SLUG_SPEEDCOM = 'speedcom';

    public function __construct(
        public readonly string $slug,
        public readonly string $companyName,
        public readonly string $companyNameFull,
        public readonly string $campaignDefaultCity,
        public readonly string $supportedLocations,
    ) {}

    public static function malan(): self
    {
        return new self(
            slug: self::SLUG_MALAN,
            companyName: 'ملان',
            companyNameFull: 'ملان انترنت',
            campaignDefaultCity: 'الطيبة',
            supportedLocations: 'كفرقاسم، كفربرا، الطيبة، الرملة، قلنسوة، وعارة/عرعرة قريب.',
        );
    }

    public static function speedcom(): self
    {
        return new self(
            slug: self::SLUG_SPEEDCOM,
            companyName: 'سبيدكوم',
            companyNameFull: 'سبيدكوم',
            campaignDefaultCity: 'رهط',
            supportedLocations: 'رهط، أكثر من 5000 بيت. قريبًا كل المناطق العربية في النقب.',
        );
    }

    public function coveragePitch(): string
    {
        return 'سبيدكوم شركة انترنت رهطاوية، موجودة بأكثر من 5000 بيت في رهط، وقريبًا بكل المناطق العربية في النقب';
    }

    public function packagesPitch(): string
    {
        return '3 حزم: 250 ميغا 99 شيكل لأول شهرين وبعدها 129 شيكل ثابت مدى الحياة، 500 ميغا 149 شيكل ثابت مدى الحياة، و1000 ميغا 129 شيكل للسنة الأولى وبعدها 159 شيكل ثابت مدى الحياة.';
    }

    public function installPitch(): string
    {
        return 'تركيب الألياف الضوئية للبيت (התקנה) اليوم بـ299 شيكل بدل 500 شيكل.';
    }

    public static function forInstance(?ChatbotInstance $instance): self
    {
        if ($instance === null) {
            return self::malan();
        }

        $settings = is_array($instance->integration_settings) ? $instance->integration_settings : [];
        $slug = strtolower(trim((string) ($settings['company_slug'] ?? $instance->integration_type ?? self::SLUG_MALAN)));

        return $slug === self::SLUG_SPEEDCOM ? self::speedcom() : self::malan();
    }

    public function isSpeedcom(): bool
    {
        return $this->slug === self::SLUG_SPEEDCOM;
    }

    /**
     * Rewrite Malan/Taybee campaign copy for this company without forking the prompt.
     */
    public function localize(string $text): string
    {
        if ($this->slug === self::SLUG_MALAN || $text === '') {
            return $text;
        }

        $city = $this->campaignDefaultCity;
        $company = $this->companyName;
        $full = $this->companyNameFull;

        $text = strtr($text, [
            'ملان إنترنت' => $full,
            'ملان انترنت' => $full,
            'شركة ملان' => 'شركة '.$company,
            'بملان' => 'ب'.$company,
            'Malan' => 'Speedcom',
            'Taybeh' => 'Rahat',
            'Taybee' => $city,
            'للطيبه' => 'ل'.$city,
            'للطيبة' => 'ل'.$city,
            'بالطيبة' => 'ب'.$city,
            'الطيبة' => $city,
            'ملان' => $company,
        ]);

        if (! $this->isSpeedcom()) {
            return $text;
        }

        $coverage = $this->coveragePitch();
        $packages = $this->packagesPitch();
        $install = $this->installPitch();

        return strtr($text, [
            'أنتِ سالي من شركة سبيدكوم — محادثة واتساب لحملة زبائن جدد (رهط).' => 'أنت ممدوح من شركة سبيدكوم — محادثة واتساب لحملة زبائن جدد (رهط). احكي عن حالك بالمذكر (أنا ممدوح، بقدر، بشرحلك). الصبية تبقى مؤنث لأنها مندوبة المبيعات. الزبون ذكر.',
            'الصبيه وسالي يبقوا مؤنث (تشرحلك / تتواصل معك / أنا سالي). الزبون ذكر.' => 'ممدوح ذكر (بشرحلك / بتواصل معك / أنا ممدوح). الصبية تبقى مؤنث. الزبون ذكر.',
            'اهلين، معك سالي من سبيدكوم. سبيدكوم بلشت من كفر قاسم، واليوم تقريباً بـ80% من البيوت والمصالح هناك.'."\n".'هالفترة الحملة وصلت لرهط: تركيب סיב אופטי مجاناً لأول 200 بيت، وأول 3 شهور 29 شيكل.'."\n".'عندكم نت بالبيت هلق؟' => "السلام عليكم، معك ممدوح من سبيدكوم. {$coverage}.\n{$install}\nعندكم نت بالبيت هلق؟",
            'سبيدكوم بلشت من كفر قاسم، واليوم تقريباً بـ80% من البيوت والمصالح هناك' => $coverage,
            'سبيدكوم بلشت من كفر قاسم واليوم تقريباً بـ80% من البيوت والمصالح هناك' => $coverage,
            'سبيدكوم بلشت من כפר קאסם واليوم تقريباً بـ80% من البيوت والمصالح هناك' => $coverage,
            'started in Kafr Qasim and is in about 80% of homes and businesses there' => 'is a Rahat internet company in more than 5000 homes, and will soon cover the Arab areas of the Negev',
            'the Rahat campaign is free סיב אופטי install for the first 200 homes and 29 for 3 months' => 'fiber install today is 299 shekels instead of 500, with three packages: 250 mega 99 for two months then 129 for life, 500 mega 149 for life, and 1000 mega 129 for the first year then 159 for life',
            'NEVER sound like a new company from Rahat' => 'Speedcom is the Rahat company, already in more than 5000 homes',
            'who Sally/Speedcom is' => 'who Mamdouh/Speedcom is',
            'keep the Kafr Qasim origin (כפר קאסם)' => 'keep that Speedcom is in more than 5000 homes in רהט',
            'keep the Kafr Qasim origin' => 'keep the Rahat coverage (more than 5000 homes)',
            'الحملة وصلت لرهط (تركيب مجاني لأول 200 + 29)' => 'الحملة في رهط (התקנה 299 بدل 500)',
            'الحملة وصلت لرهط (تركيب مجاني لأول 200، أول 3 شهور 29)' => 'الحملة في رهط (התקנה 299 بدل 500)',
            'الحملة هلق وصلت لرهط' => 'سبيدكوم موجودة في رهط',
            'رهط حملة جديدة، مش أصل الشركة' => 'رهط بلد الشركة من الأساس',
            'تمام، התקנה סיב אופטי وتركيب بشكل عام وبباقي الشركات مثل بيزك بتكون تكلفة بين 300-500 شيقل. احنا عاملين حملة لأول 200 زبون برهط تركيب مجاني تماما.' => 'تمام، تركيب الألياف عند الشركات الثانية حوالي 500 شيكل. '.$install,
            'عنا لأول 200 بيت برهط التركيب مجاني.' => $install,
            'احنا عاملين حملة لأول 200 زبون برهط تركيب مجاني تماما.' => $install,
            'وأول 3 شهور بس 29 شيقل. بعدين 149 ثابت مدى الحياة.' => $packages,
            'أول 3 شهور 29 شيكل، وبعدين 149 ثابت.' => $packages,
            'أول 3 شهور بس 29 شيقل، وהתקנת סיב אופטי المجانية' => 'התקנה اليوم 299 شيكل بدل 500، وחבילת الـ1000 ميغا 129 شيكل عالشهر لأول سنة وبعدها بتغير لـ159',
            'السعر الي عاملينه هسا لرهط قوي كثير… 29 شيقل أول 3 شهور، وبعدها 149 ثابت مدى الحياه — سعر واضح بدون مفاجآت بين السطور. وبمناسبة انتهائنا من تمديد البنية التحتية برهط، التركيب مجاني، وأوّل 200 زبون بهالحمله بيوخذو מגדיל טווח مجاني.' => 'الأسعار المعتمدة: '.$packages.' '.$install,
            'إذا سأل السعر فورًا — جاوب فورًا: 29 أول 3 شهور → 149 ثابت مدى الحياه + تركيب مجاني + מגדיל טווח لأول 200 بهالحملة.' => 'إذا سأل السعر فورًا — جاوب فورًا من الحزم الثلاث: '.$packages.' '.$install,
            'سعر الحملة: 29 شيقل أول 3 شهور، بعدها 149 ثابت مدى الحياه، واضح بدون مفاجآت + تركيب مجاني + מגדיל טווח لأول 200' => 'سعر الحزم: '.$packages.' '.$install,
            'العرض بالافتتاحية (تركيب مجاني + 29)' => 'العرض بالافتتاحية (התקנה 299 بدل 500)',
            'إذا حكى إنه دافع أقل من 149: اسألي شو الشركة وكم بدفع. ممنوع تعيدي 149 كأنه أرخص. التركيب المجاني ودعم الجمعة والسبت بس إذا بهمه.' => 'إذا سأل عن السعر: جاوب من الحزم الثلاث فقط، ممنوع تخترع رقم. إذا حكى إنه دافع أقل: اسأل شو الشركة وكم بدفع، وما تقارن إلا بالحزمة الي تناسب سرعته.',
            'التركيب مجاني' => $install,
            'تركيب مجاني' => $install,
            '300-500 elsewhere vs free for the first 200, then 29 then 149' => 'install today is 299 shekels instead of 500. '.$packages,
            'install elsewhere is 300-500 vs free for the first 200, then 29 then 149' => 'install today is 299 shekels instead of 500. '.$packages,
            '300-500 vs free for first 200, then 29/149' => 'install today is 299 instead of 500, then the three packages',
            'If they pay less than 149, ask what they pay. Do not claim 149 is cheaper.' => 'If they ask the price, quote only the three packages and the 299 install. Do not invent a price.',
            'If they already pay LESS than 149, ask which company and how much. Do not repeat 149 as if it were cheaper. Mention free install and Friday-Saturday support only if that matters to them.' => 'If they ask the price, quote the matching package. Install today is 299 instead of 500. Mention Friday-Saturday support only if that matters to them.',
            'first 200 get מגדיל טווח free; free install' => 'install today is 299 shekels instead of 500. Quote only the three packages',
            'ما تخترعي أسعار / رقم سعر.' => 'الأسعار المعتمدة هي حزم 250 و500 و1000 وהתקנה 299 فقط. ممنوع أي سعر غيرها.',
            'احكي زي بنت من البلد' => 'احكي زي شب من رهط',
            'أنتِ سالي' => 'أنت ممدوح',
            'أنا سالي' => 'أنا ممدوح',
            'معك سالي' => 'معك ممدوح',
            'مين سالي' => 'مين ممدوح',
            'مندوبة' => 'مندوب',
            'سالي' => 'ممدوح',
            'Sally' => 'Mamdouh',
            'كفر قاسم' => 'رهط',
            'Kafr Qasim' => 'Rahat',
            'כפר קאסם' => 'רהט',
        ]);
    }
}
