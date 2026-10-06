@php
    $requested = request()->query('lang');
    $lang = in_array($requested, ['ar', 'en', 'he'], true) ? $requested : 'ar';
    $isRtl = in_array($lang, ['ar', 'he'], true);
    $focus = ($focus ?? 'policy') === 'choices' ? 'choices' : 'policy';

    $copy = [
        'en' => [
            'title' => 'Kaman POS Privacy Policy',
            'choices_title' => 'Your privacy choices',
            'updated' => 'Last updated: 23 September 2026',
            'apps' => 'This page covers Kaman POS and Kaman Pos Lite.',
            'who_h' => 'Who we are',
            'who' => 'Kaman builds point-of-sale software for restaurants. The App Store developer is Yamen Amer. Questions about this policy go to healpy245@gmail.com or 0584680001, or through a support ticket inside the app.',
            'device_h' => 'Data that stays on the device',
            'device' => [
                'Face sign-in, when a restaurant turns it on, uses the camera to store a staff photo and a face template on that device. Those are used only to recognize staff on that device. Face sign-in does not upload the photo or the template.',
                'Receipt and kitchen printer settings stay on the device. Printing uses the restaurant’s local network.',
            ],
            'restaurant_h' => 'Data sent to the restaurant’s Kaman account',
            'restaurant' => 'To run the restaurant, the app sends staff sign-in (name and role), orders, items, tables, and payment method and amounts to that restaurant’s Kaman server. The restaurant controls that account. Kaman operates the service.',
            'support_h' => 'Data sent to Kaman support',
            'support_lead' => 'When support tools are on, the app sends diagnostics to kaman-workspace.com so Kaman can help with that device:',
            'support' => [
                'Device identifier, app version, platform, and computer name',
                'Restaurant name',
                'Cashier name and email',
                'App logs and printer status',
                'Support messages the user sends',
                'Microphone audio, only when the user talks to support, so the message can be transcribed',
            ],
            'others_h' => 'Other services',
            'others' => 'Support answers and voice transcription, when those features are used, are processed by OpenAI. Printer traffic stays on the restaurant’s local network.',
            'not_h' => 'What we do not do',
            'not' => [
                'We do not sell personal information.',
                'We do not use the app for advertising or to track people across other companies’ apps and websites.',
                'We do not collect precise location, contacts, health data, or browsing history.',
            ],
            'keep_h' => 'How long we keep it',
            'keep' => 'Order and staff data stay in the restaurant’s Kaman account for as long as that account needs them. Failed and error logs from the POS are deleted automatically after 14 days. Routine success logs are deleted after one day. Tickets stay until the restaurant asks or they are no longer needed.',
            'choices_h' => 'Your choices',
            'choices' => [
                'Face sign-in can be turned off in the app. Removing a worker deletes that face template from the device.',
                'The microphone is used only when someone talks to support.',
                'A restaurant can ask Kaman to delete support logs and tickets for its devices.',
                'To ask for a copy or deletion of support data, email healpy245@gmail.com, call 0584680001, or open a support ticket in the app. Include the restaurant name and, if you have it, the device name.',
            ],
            'children_h' => 'Children',
            'children' => 'Kaman POS is business software for restaurant staff. It is not directed at children.',
            'links' => 'Privacy policy',
            'choices_link' => 'Privacy choices',
        ],
        'ar' => [
            'title' => 'سياسة خصوصية كمان POS',
            'choices_title' => 'خيارات الخصوصية',
            'updated' => 'آخر تحديث: 23 أيلول 2026',
            'apps' => 'هذه الصفحة تخص تطبيق Kaman POS وKaman Pos Lite.',
            'who_h' => 'من نحن',
            'who' => 'كمان تبني برنامج نقاط البيع للمطاعم. مطوّر التطبيق على آب ستور هو يامن عامر. أسئلة هذه السياسة تُرسل إلى healpy245@gmail.com أو على 0584680001، أو عبر تذكرة دعم من داخل التطبيق.',
            'device_h' => 'بيانات تبقى على الجهاز',
            'device' => [
                'تسجيل الدخول بالوجه، إذا فعّله المطعم، يستخدم الكاميرا لحفظ صورة الموظف وقالب الوجه على ذلك الجهاز فقط للتعرّف على الموظفين. هذه الميزة لا ترفع الصورة ولا قالب الوجه.',
                'إعدادات طابعات الفاتورة والمطبخ تبقى على الجهاز. الطباعة تتم عبر الشبكة المحلية للمطعم.',
            ],
            'restaurant_h' => 'بيانات تُرسل إلى حساب كمان الخاص بالمطعم',
            'restaurant' => 'لتشغيل المطعم يرسل التطبيق تسجيل دخول الموظفين (الاسم والدور) والطلبات والأصناف والطاولات وطريقة الدفع والمبالغ إلى خادم كمان الخاص بذلك المطعم. المطعم يدير حسابه، وكمان تشغّل الخدمة.',
            'support_h' => 'بيانات تُرسل إلى دعم كمان',
            'support_lead' => 'عند تشغيل أدوات الدعم يرسل التطبيق معلومات تشخيص إلى kaman-workspace.com لمساعدة كمان في ذلك الجهاز:',
            'support' => [
                'معرّف الجهاز وإصدار التطبيق والمنصة واسم الحاسوب',
                'اسم المطعم',
                'اسم الكاشير وبريده',
                'سجلات التطبيق وحالة الطابعات',
                'رسائل الدعم التي يرسلها المستخدم',
                'صوت الميكروفون فقط عندما يتحدث المستخدم مع الدعم، ليتم تفريغ الكلام نصاً',
            ],
            'others_h' => 'خدمات أخرى',
            'others' => 'ردود الدعم وتفريغ الصوت، عند استخدام هذه الميزات، تعالجها OpenAI. حركة الطابعات تبقى على الشبكة المحلية للمطعم.',
            'not_h' => 'ما لا نفعله',
            'not' => [
                'لا نبيع المعلومات الشخصية.',
                'لا نستخدم التطبيق للإعلان ولا لتتبع الأشخاص عبر تطبيقات ومواقع شركات أخرى.',
                'لا نجمع الموقع الدقيق ولا جهات الاتصال ولا بيانات صحية ولا سجل التصفح.',
            ],
            'keep_h' => 'مدة الاحتفاظ',
            'keep' => 'بيانات الطلبات والموظفين تبقى في حساب كمان الخاص بالمطعم طالما يحتاجها الحساب. سجلات الفشل والأخطاء من نقطة البيع تُحذف تلقائياً بعد 14 يوماً. سجلات النجاح العادية تُحذف بعد يوم واحد. التذاكر تبقى حتى يطلب المطعم حذفها أو عندما لا تعود لازمة.',
            'choices_h' => 'خياراتك',
            'choices' => [
                'يمكن إيقاف تسجيل الدخول بالوجه من إعدادات التطبيق. حذف الموظف يمسح قالب وجهه من الجهاز.',
                'الميكروفون يُستخدم فقط عندما يتحدث أحد مع الدعم.',
                'يمكن للمطعم أن يطلب من كمان حذف سجلات الدعم وتذاكره لأجهزته.',
                'لطلب نسخة من بيانات الدعم أو حذفها، أرسل إلى healpy245@gmail.com أو اتصل على 0584680001، أو افتح تذكرة دعم داخل التطبيق. اذكر اسم المطعم واسم الجهاز إن وُجد.',
            ],
            'children_h' => 'الأطفال',
            'children' => 'كمان POS برنامج عمل لموظفي المطاعم، وهو غير موجّه للأطفال.',
            'links' => 'سياسة الخصوصية',
            'choices_link' => 'خيارات الخصوصية',
        ],
        'he' => [
            'title' => 'מדיניות הפרטיות של Kaman POS',
            'choices_title' => 'אפשרויות הפרטיות שלך',
            'updated' => 'עודכן לאחרונה: 23 בספטמבר 2026',
            'apps' => 'העמוד הזה מכסה את Kaman POS ואת Kaman Pos Lite.',
            'who_h' => 'מי אנחנו',
            'who' => 'קמאן בונה תוכנת קופה למסעדות. המפתח בחנות האפליקציות הוא יאמן עאמר. שאלות על המדיניות הזו נשלחות אל healpy245@gmail.com או ל-0584680001, או דרך קריאת תמיכה מתוך האפליקציה.',
            'device_h' => 'נתונים שנשארים במכשיר',
            'device' => [
                'כניסה בזיהוי פנים, כשמסעדה מפעילה אותה, משתמשת במצלמה כדי לשמור תמונת עובד ותבנית פנים על המכשיר הזה בלבד, כדי לזהות עובדים. התכונה לא מעלה את התמונה או את התבנית.',
                'הגדרות מדפסות קבלה ומטבח נשארות במכשיר. ההדפסה עוברת ברשת המקומית של המסעדה.',
            ],
            'restaurant_h' => 'נתונים שנשלחים לחשבון קמאן של המסעדה',
            'restaurant' => 'כדי להפעיל את המסעדה האפליקציה שולחת כניסת עובדים (שם ותפקיד), הזמנות, פריטים, שולחנות, אמצעי תשלום וסכומים לשרת קמאן של אותה מסעדה. המסעדה מנהלת את החשבון, וקמאן מפעילה את השירות.',
            'support_h' => 'נתונים שנשלחים לתמיכת קמאן',
            'support_lead' => 'כשכלי התמיכה פעילים, האפליקציה שולחת אבחון אל kaman-workspace.com כדי שקמאן תוכל לעזור עם המכשיר:',
            'support' => [
                'מזהה מכשיר, גרסת אפליקציה, פלטפורמה ושם המחשב',
                'שם המסעדה',
                'שם הקופאי והאימייל',
                'יומני האפליקציה ומצב המדפסות',
                'הודעות תמיכה שהמשתמש שולח',
                'שמע מהמיקרופון רק כשהמשתמש מדבר עם התמיכה, כדי לתמלל את ההודעה',
            ],
            'others_h' => 'שירותים נוספים',
            'others' => 'תשובות תמיכה ותמלול קול, כשמשתמשים בתכונות האלה, מעובדים על ידי OpenAI. תעבורת המדפסות נשארת ברשת המקומית של המסעדה.',
            'not_h' => 'מה אנחנו לא עושים',
            'not' => [
                'אנחנו לא מוכרים מידע אישי.',
                'אנחנו לא משתמשים באפליקציה לפרסום ולא למעקב אחרי אנשים בין אפליקציות ואתרים של חברות אחרות.',
                'אנחנו לא אוספים מיקום מדויק, אנשי קשר, נתוני בריאות או היסטוריית גלישה.',
            ],
            'keep_h' => 'כמה זמן שומרים',
            'keep' => 'נתוני הזמנות ועובדים נשארים בחשבון קמאן של המסעדה כל עוד החשבון צריך אותם. יומני כשל ושגיאה מהקופה נמחקים אוטומטית אחרי 14 יום. יומני הצלחה רגילים נמחקים אחרי יום אחד. פניות נשארות עד שהמסעדה מבקשת או עד שאין בהן צורך.',
            'choices_h' => 'האפשרויות שלך',
            'choices' => [
                'אפשר לכבות זיהוי פנים בהגדרות האפליקציה. מחיקת עובד מוחקת את תבנית הפנים מהמכשיר.',
                'המיקרופון בשימוש רק כשמישהו מדבר עם התמיכה.',
                'מסעדה יכולה לבקש מקמאן למחוק יומני תמיכה וקריאות של המכשירים שלה.',
                'כדי לבקש עותק או מחיקה של נתוני תמיכה, כתבו אל healpy245@gmail.com, התקשרו ל-0584680001, או פתחו קריאת תמיכה באפליקציה. ציינו את שם המסעדה ואת שם המכשיר אם יש.',
            ],
            'children_h' => 'ילדים',
            'children' => 'Kaman POS היא תוכנה עסקית לצוות מסעדות. היא לא מיועדת לילדים.',
            'links' => 'מדיניות פרטיות',
            'choices_link' => 'אפשרויות פרטיות',
        ],
    ];
    $t = $copy[$lang];
    $pageTitle = $focus === 'choices' ? $t['choices_title'] : $t['title'];
    $q = static fn (string $code): string => request()->fullUrlWithQuery(['lang' => $code]);
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700;800&family=Heebo:wght@400;500;700&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { margin: 0; background: #f7f7f8; color: #1a1a1a; font-family: {{ $lang === 'ar' ? "'Almarai', sans-serif" : ($lang === 'he' ? "'Heebo', sans-serif" : "'Poppins', sans-serif") }}; line-height: 1.65; }
        main { max-width: 42rem; margin: 0 auto; padding: 2.5rem 1.25rem 4rem; }
        header { display: flex; justify-content: space-between; gap: 1rem; align-items: center; margin-bottom: 1.5rem; }
        nav a { color: #9a5b12; text-decoration: none; margin-inline-start: 0.75rem; font-size: 0.9rem; }
        nav a[aria-current="true"] { font-weight: 700; }
        h1 { font-size: 1.75rem; line-height: 1.3; margin: 0 0 0.35rem; }
        .meta { color: #5c5c66; margin: 0 0 1.5rem; }
        section { background: #fff; border: 1px solid #ececef; border-radius: 14px; padding: 1.1rem 1.2rem; margin: 0 0 0.85rem; }
        h2 { font-size: 1.05rem; margin: 0 0 0.45rem; }
        p, li { margin: 0.35rem 0; }
        ul { padding-inline-start: 1.2rem; }
        a { color: #9a5b12; }
    </style>
</head>
<body>
<main>
    <header>
        <strong>Kaman</strong>
        <nav>
            @foreach (['ar' => 'العربية', 'he' => 'עברית', 'en' => 'English'] as $code => $label)
                <a href="{{ $q($code) }}" @if ($code === $lang) aria-current="true" @endif>{{ $label }}</a>
            @endforeach
        </nav>
    </header>
    <h1>{{ $pageTitle }}</h1>
    <p class="meta">{{ $t['updated'] }} · {{ $t['apps'] }}</p>
    <p>
        <a href="{{ route('privacy', ['lang' => $lang]) }}">{{ $t['links'] }}</a>
        ·
        <a href="{{ route('privacy.choices', ['lang' => $lang]) }}">{{ $t['choices_link'] }}</a>
    </p>

    @if ($focus === 'policy')
        <section>
            <h2>{{ $t['who_h'] }}</h2>
            <p>{{ $t['who'] }}</p>
            <p>
                <a href="mailto:healpy245@gmail.com">healpy245@gmail.com</a>
                ·
                <a href="tel:+972584680001">0584680001</a>
            </p>
        </section>
        <section>
            <h2>{{ $t['device_h'] }}</h2>
            @foreach ($t['device'] as $item)
                <p>{{ $item }}</p>
            @endforeach
        </section>
        <section>
            <h2>{{ $t['restaurant_h'] }}</h2>
            <p>{{ $t['restaurant'] }}</p>
        </section>
        <section>
            <h2>{{ $t['support_h'] }}</h2>
            <p>{{ $t['support_lead'] }}</p>
            <ul>
                @foreach ($t['support'] as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        </section>
        <section>
            <h2>{{ $t['others_h'] }}</h2>
            <p>{{ $t['others'] }}</p>
        </section>
        <section>
            <h2>{{ $t['not_h'] }}</h2>
            <ul>
                @foreach ($t['not'] as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        </section>
        <section>
            <h2>{{ $t['keep_h'] }}</h2>
            <p>{{ $t['keep'] }}</p>
        </section>
    @endif

    <section id="choices">
        <h2>{{ $t['choices_h'] }}</h2>
        <ul>
            @foreach ($t['choices'] as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
        @if ($focus === 'choices')
            <p><a href="{{ route('privacy', ['lang' => $lang]) }}">{{ $t['links'] }}</a></p>
        @endif
    </section>

    @if ($focus === 'choices')
        <section>
            <h2>{{ $t['who_h'] }}</h2>
            <p>{{ $t['who'] }}</p>
            <p>
                <a href="mailto:healpy245@gmail.com">healpy245@gmail.com</a>
                ·
                <a href="tel:+972584680001">0584680001</a>
            </p>
        </section>
    @else
        <section>
            <h2>{{ $t['children_h'] }}</h2>
            <p>{{ $t['children'] }}</p>
        </section>
    @endif
</main>
</body>
</html>
