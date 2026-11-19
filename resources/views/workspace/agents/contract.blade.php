@php
    use App\Support\FinanceMoney;
    /**
     * The Kaman service agreement with the quote in it. Modes: "document" (agent and Finance: print and PDF) and
     * "sign" (the client's link: read, then sign at the bottom).
     *
     * @var \App\Models\Sales\SalesQuote $quote
     * @var array<string, mixed> $contract
     * @var \App\Models\Sales\SalesQuoteSignature|null $signature  Set once signed.
     */
    $signing = $mode === 'sign' && $signature === null;
    $signed = $contract['signed'] ?? null;
    $shekels = fn ($amount) => number_format((float) $amount, fmod((float) $amount, 1.0) === 0.0 ? 0 : 2).' ש"ח';
    $blank = fn ($value, $width = 'md') => filled($value) ? '<b class="ct-fill">'.e($value).'</b>' : '<span class="ct-blank ct-blank--'.$width.'"></span>';
    $groups = [
        'monthly' => __('sales.contract.groups.monthly'),
        'services' => __('sales.contract.groups.services'),
        'devices' => __('sales.contract.groups.devices'),
    ];
    $signatureImage = $signature?->signatureDataUri();
@endphp
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('sales.contract.title') }} — {{ $contract['customer'] }}</title>
    <link rel="icon" type="image/png" href="{{ asset('kaman.png') }}">
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/workspace-shell.css') }}?v={{ filemtime(public_path('css/workspace-shell.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/finance-workspace.css') }}?v={{ filemtime(public_path('css/finance-workspace.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/contract.css') }}?v={{ filemtime(public_path('css/contract.css')) }}">
</head>
<body class="fin-doc-page ct-page {{ $pdf ? 'is-pdf' : '' }}">
    @unless($pdf)
        <div class="fin-doc-tools">
            @if($mode === 'document')
                <a class="fin-btn fin-btn--ghost" href="{{ route('workspace.agents.sales', ['open' => $quote->id]) }}">{{ __('sales.doc.back') }}</a>
            @endif
            <button type="button" class="fin-btn fin-btn--primary" onclick="window.print()">{{ __('sales.doc.print') }}</button>
        </div>
    @endunless

    @if($mode === 'sign' && session('signed'))
        <div class="ct-banner is-ok" role="status">{{ __('sales.contract.signed_thanks') }}</div>
    @elseif($mode === 'sign' && $signature !== null)
        <div class="ct-banner is-ok" role="status">{{ __('sales.contract.already_signed', ['date' => $signed['at'] ?? '']) }}</div>
    @elseif($signing)
        <div class="ct-banner" role="note">{{ __('sales.contract.read_then_sign') }}</div>
    @endif

    <article class="fin-paper ct-paper">
        <header class="fin-paper__head ct-head">
            <div class="ct-logos">
                <img src="{{ asset('kaman.png') }}" alt="KAMAN" class="ct-logo">
                <img src="{{ asset('images/contract/mfit-group.png') }}" alt="MFIT Group" class="ct-logo ct-logo--mfit">
            </div>
            <div class="fin-paper__type">
                <h1>הסכם התקשרות לשירותי אפליקציית KAMAN</h1>
                <span>{{ __('sales.contract.quote_ref', ['number' => $contract['number']]) }} · {{ $contract['date'] }}</span>
            </div>
        </header>

        <section class="ct-parties">
            <p>הסכם התקשרות זה ("ההסכם") נערך ונחתם ביום {!! $blank($signed['day'] ?? null, 'sm') !!} לחודש {!! $blank($signed['month'] ?? null, 'sm') !!} שנת {!! $blank($signed['year'] ?? null, 'sm') !!}, בין:</p>
            <p>אמ.פיי איי טי גרופ בע"מ, ח.פ. 517119558, שמשרדיה הרשומים בכתובת התאנה 16 לב הארץ (להלן: "החברה"),</p>
            <p>מצד אחד;</p>
            <p class="ct-between">לבין</p>
            <p>{!! $blank($contract['customer']) !!}, ח.פ./ע.מ. {!! $blank($contract['business_number']) !!}, שמקום עסקיו בכתובת {!! $blank($contract['address'], 'lg') !!} (להלן: "הלקוח"),</p>
            <p>מצד שני.</p>
        </section>

        <section class="ct-quote">
            <h2 class="ct-quote__title">
                <span>{{ __('sales.contract.quote_title') }}</span>
                <small dir="ltr">#{{ $contract['number'] }}</small>
            </h2>
            @foreach($groups as $key => $label)
                @continue(empty($contract['groups'][$key]))
                <h3 class="sq-doc-section ct-group">{{ $label }}</h3>
                <table class="fin-paper__lines ct-lines">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>{{ __('sales.contract.item') }}</th>
                            <th>{{ __('sales.doc.qty') }}</th>
                            <th>{{ __('sales.doc.unit') }}</th>
                            <th>{{ __('sales.doc.line') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($contract['groups'][$key] as $line)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td>{{ $line['name'] }}</td>
                                <td class="ds-num">{{ $line['quantity'] }}</td>
                                <td class="ds-num">{{ FinanceMoney::format($line['unit_price']) }}</td>
                                <td class="ds-num">{{ FinanceMoney::format($line['line_total']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach

            <section class="fin-paper__totals ct-totals">
                @if($contract['totals']['monthly'] > 0)
                    <div><span>{{ __('sales.contract.totals.monthly') }}</span><b class="ds-num">{{ FinanceMoney::format($contract['totals']['monthly']) }}</b></div>
                @endif
                @if($contract['totals']['services'] > 0)
                    <div><span>{{ __('sales.contract.totals.services') }}</span><b class="ds-num">{{ FinanceMoney::format($contract['totals']['services']) }}</b></div>
                @endif
                @if($contract['totals']['devices'] > 0)
                    <div><span>{{ __('sales.contract.totals.devices') }}</span><b class="ds-num">{{ FinanceMoney::format($contract['totals']['devices']) }}</b></div>
                @endif
                @if($contract['totals']['services'] + $contract['totals']['devices'] > 0)
                    <div class="is-total"><span>{{ __('sales.contract.totals.one_time') }}</span><b class="ds-num">{{ FinanceMoney::format($contract['totals']['services'] + $contract['totals']['devices']) }}</b></div>
                @endif
            </section>
            <p class="fin-paper__notes">{{ __('sales.contract.no_vat') }}@if(filled($contract['notes']))<br>{{ $contract['notes'] }}@endif</p>
        </section>

        <section class="ct-terms">
            <h3>1. רקע והצהרות</h3>
            <p>1.1 החברה היא מפתחת אפליקציית KAMAN (להלן: "האפליקציה"), מערכת דיגיטלית לניהול פעילות עסקית בתחום ההסעדה הכוללת: קופה רושמת, ניהול הזמנות, ניהול קשרי לקוחות, היסטוריית לקוחות, ניהול משלוחים מול מובילים, כלי CRM ותכונות נוספות.</p>
            <p>1.2 החברה היא בעלת הזכויות הבלעדיות באפליקציה ובכל רכיביה, לרבות זכויות היוצרים, הפטנטים, סימני המסחר והקניין הרוחני.</p>
            <p>1.3 הלקוח הוא בעל עסק בתחום ההסעדה ומעוניין לקבל רישיון שימוש באפליקציה לצורך ניהול עסקו.</p>
            <p>1.4 הלקוח מצהיר כי עיין בהצעת המחיר שצורפה להסכם זה וכי היא מהווה חלק בלתי נפרד ממנו.</p>
            <p>1.5 הלקוח מצהיר כי ידוע לו שהפעלת האפליקציה כקופה רושמת מחייבת עמידה בדרישות הדין, והוא האחראי הבלעדי לכך.</p>

            <h3>2. הגדרות</h3>
            <p>לצורך הסכם זה יהיו למונחים שלהלן המשמעות שלצידם:</p>
            <p>"החברה" – אמ.פיי איי טי גרופ בע"מ.</p>
            <p>"האפליקציה" – אפליקציית KAMAN לרבות רכיביה, המודולים הנלווים, עדכוניה ושדרוגיה.</p>
            <p>"הלקוח" – מסעדה או בית עסק אחר בתחום ההסעדה אשר התקשר עם החברה.</p>
            <p>"השירותים" – מתן גישה לאפליקציה, התקנה, הדרכה, תחזוקה, עדכונים ותמיכה בהתאם להסכם זה.</p>
            <p>"דמי מנוי" – התמורה החודשית בעד השימוש באפליקציה כפי שמפורט בסעיף 4 להלן.</p>
            <p>"נתוני לקוח הקצה" – מידע שנאסף באפליקציה על ידי הלקוח לגבי לקוחותיו (פרטי הזמנות, פרטי התקשרות, היסטוריה).</p>
            <p>"ציוד קצה" – ציוד פיזי נלווה כגון מסופי אשראי, מדפסות וקופות פיזיות.</p>

            <h3>3. מתן רישיון שימוש</h3>
            <p>3.1 החברה מעניקה ללקוח רישיון שימוש אישי, מוגבל, לא בלעדי ובלתי ניתן להעברה באפליקציה, בהתאם לתנאי הסכם זה.</p>
            <p>3.2 הלקוח לא יהיה רשאי להעתיק, לשנות, למכור, להעביר, למסור רישיון משנה, לבצע הנדסה הפוכה או לפתח מוצר מתחרה בהתבסס על האפליקציה.</p>
            <p>3.3 כל הזכויות הקנייניות באפליקציה, לרבות הקוד, התכנים, הממשק, סימני המסחר וכל יצירה נלווית – יישארו בבעלות מלאה של החברה.</p>

            <h3>4. תשלומים</h3>
            <p>4.1 הלקוח ישלם לחברה:</p>
            <p class="ct-sub">א. תשלום חד פעמי בגין התקנת המערכת והדרכת צוות הלקוח – @if($contract['totals']['services'] > 0)בסך <b class="ct-fill">{{ $shekels($contract['totals']['services']) }}</b>.@else<b class="ct-fill">{{ __('sales.contract.no_charge') }}</b>.@endif</p>
            <p class="ct-sub">ב. דמי מנוי חודשיים בגין השימוש באפליקציה – @if($contract['totals']['monthly'] > 0)בסך <b class="ct-fill">{{ $shekels($contract['totals']['monthly']) }}</b> לחודש.@else<b class="ct-fill">{{ __('sales.contract.no_charge') }}</b>.@endif</p>
            <p>4.2 האמור לעיל אינו כולל עלות ציוד קצה. פירוט עלויות הציוד מופיע בהצעת המחיר המצורפת ומהווה חלק בלתי נפרד מהסכם זה.</p>
            <p>4.3 לחברה שמורה הזכות לעדכן את התעריפים מעת לעת, לפי שיקול דעתה, בהודעה מראש ללקוח.</p>
            <p>4.4 לא יינתן החזר כספי בגין התקנת תוכנה, הדרכות או שירותים שסופקו בפועל.</p>
            <p>4.5 המחירים אינם כוללים מע"מ. לכל סכום יש להוסיף מע"מ כחוק.</p>
            <p>4.6 התשלומים יבוצעו כנגד חשבונית מס כדין, לא יאוחר מ-14 ימים ממועד הוצאת החשבונית.</p>

            <h3>5. שירותי תמיכה ותחזוקה</h3>
            <p>5.1 החברה תספק ללקוח שירותי תחזוקה, עדכונים ותיקוני תקלות בהתאם למדיניות השירות של החברה.</p>
            <p>5.2 זמני תגובה: לתקלות קריטיות (השבתת מערכת מלאה) – מענה בתוך ___ שעות; לתקלות רגילות – מענה בתוך ___ ימי עסקים.</p>
            <p>5.3 החברה לא תהיה אחראית לבעיות הנובעות מתשתית אינטרנט לקויה, ציוד קצה פגום או כל גורם חיצוני שאינו בשליטתה.</p>
            <p>5.4 שירותי תמיכה מורחבים (מעבר לסטנדרט) יסופקו בתשלום נוסף כפי שייקבע מעת לעת.</p>

            <h3>6. אחריות והגבלת אחריות</h3>
            <p>6.1 החברה מספקת את האפליקציה כפי שהיא (As-Is), עם ההתאמות שנדרשו על ידי הלקוח מראש ובוצעו על ידי החברה.</p>
            <p>6.2 החברה לא תישא בכל אחריות לנזקים עקיפים או תוצאתיים, לרבות אובדן רווחים, נזקי מוניטין או אובדן נתונים.</p>
            <p>6.3 אחריותה הכוללת של החברה בכל מקרה לא תעלה על סך דמי המנוי ששילם הלקוח במהלך 12 החודשים שקדמו למועד האירוע.</p>
            <p>6.4 הלקוח אחראי באופן מלא לעמידה בכל דרישות הדין בנוגע לניהול עסקו, לרבות חובותיו מול רשות המיסים.</p>

            <h3>7. נתונים ומידע</h3>
            <p>7.1 נתוני לקוחות הקצה שנרשמים באפליקציה שייכים ללקוח בלבד.</p>
            <p>7.2 החברה רשאית להשתמש במידע אנונימי וסטטיסטי לצורך שיפור השירות והפעלת האפליקציה.</p>
            <p>7.3 החברה מתחייבת לשמור על סודיות הנתונים ולפעול בהתאם לחוקי הגנת הפרטיות בישראל.</p>
            <p>7.4 החברה תבצע גיבויים תקופתיים לנתוני הלקוח.</p>

            <h3>8. אבטחת מידע</h3>
            <p>8.1 החברה מתחייבת לנקוט באמצעי אבטחת מידע בהתאם לסטנדרטים המקובלים בתעשייה.</p>
            <p>8.2 הלקוח אחראי לנקוט באמצעי זהירות בסיסיים (סיסמאות חזקות, אבטחת מכשירים).</p>

            <h3>9. התחייבויות הלקוח</h3>
            <p>9.1 הלקוח מתחייב להשתמש באפליקציה בהתאם להוראות הדין, לתכליתה ולמטרותיה.</p>
            <p>9.2 הלקוח ישא באחריות לפעולות עובדיו וכל מי שמקבל ממנו גישה לאפליקציה.</p>
            <p>9.3 הלקוח לא יעשה שימוש באפליקציה לצרכים אסורים, בלתי חוקיים או פוגעניים.</p>

            <h3>10. משך ההסכם וסיומו</h3>
            <p>10.1 ההסכם נכנס לתוקף במועד חתימתו ויישאר בתוקף לתקופה של שנה, מתחדש אוטומטית לתקופות נוספות של שנה כל פעם, אלא אם נמסרה הודעה בכתב על סיום ההתקשרות 30 ימים מראש.</p>
            <p>10.2 החברה תהיה רשאית להפסיק את ההתקשרות באופן מיידי במקרה של הפרת ההסכם או אי-תשלום.</p>
            <p>10.3 בסיום ההתקשרות, תעביר החברה ללקוח את כלל נתוני המסעדה שנשמרו באפליקציה עד למועד הסיום, בפורמט סביר ומקובל.</p>

            <h3>11. קניין רוחני</h3>
            <p>11.1 כל הזכויות באפליקציה ובקניין הרוחני הנלווה לה – שייכות לחברה בלבד.</p>
            <p>11.2 ללקוח אין כל זכות קניינית בקוד המקור, בעיצוב, במותג או בשם המסחרי KAMAN.</p>

            <h3>12. שיפוי</h3>
            <p>12.1 הלקוח ישפה את החברה בגין כל נזק, הפסד או תביעה שתוגש נגדה עקב שימוש לא חוקי או בלתי תקין שלו באפליקציה.</p>

            <h3>13. סודיות</h3>
            <p>13.1 שני הצדדים מתחייבים לשמור בסודיות כל מידע עסקי, טכנולוגי או מסחרי שהגיע לידיהם במסגרת ההתקשרות.</p>
            <p>13.2 ההתחייבות לסודיות תעמוד בתוקף גם לאחר סיום ההסכם.</p>

            <h3>14. שונות</h3>
            <p>14.1 הסכם זה ממצה את מלוא ההבנות בין הצדדים ומחליף כל הסכמה קודמת.</p>
            <p>14.2 כל שינוי או תוספת להסכם זה יחייבו רק אם נעשו בכתב ובחתימת שני הצדדים.</p>
            <p>14.3 כותרות הסעיפים בהסכם זה נועדו לנוחות בלבד ולא ישמשו בפרשנותו.</p>
        </section>

        <section class="ct-sign">
            <h3>ולראיה באו הצדדים על החתום:</h3>
            <div class="ct-sign__row">
                <div class="ct-sign__box">
                    <div class="ct-sign__mark">
                        @if($signatureImage)
                            <img src="{{ $signatureImage }}" alt="{{ __('sales.contract.client_signature') }}">
                        @endif
                    </div>
                    <span class="ct-sign__line">חתימת לקוח</span>
                    @if($signed)
                        <small>{{ $signed['name'] }} · {{ $signed['at'] }}</small>
                    @endif
                </div>
                <div class="ct-sign__box">
                    <div class="ct-sign__mark">
                        <img src="{{ asset('images/contract/mfit-group.png') }}" alt="MFIT Group" class="ct-sign__stamp">
                    </div>
                    <span class="ct-sign__line">אמ.פיי איי טי גרופ בע"מ</span>
                </div>
            </div>
        </section>
        @if(filled($contract['agent']))
            <footer class="fin-paper__foot">{{ __('sales.doc.agent') }} {{ $contract['agent'] }}</footer>
        @endif
    </article>

    @if($signing)
        <form method="post" action="{{ route('contracts.sign', $link->token) }}" class="ct-form" data-ct-form novalidate>
            @csrf
            <h2>{{ __('sales.contract.sign_title') }}</h2>
            <p class="ct-form__hint">{{ __('sales.contract.sign_hint') }}</p>
            @if($errors->any())
                <div class="ct-banner is-bad" role="alert">{{ $errors->first() }}</div>
            @endif
            <label class="ct-field">
                <span>{{ __('sales.contract.signer_name') }}</span>
                <input type="text" name="signer_name" required maxlength="191" autocomplete="name" value="{{ old('signer_name', $contract['contact']) }}">
            </label>
            <div class="ct-form__grid">
                <label class="ct-field">
                    <span>{{ __('sales.contract.business_number') }}</span>
                    <input type="text" name="signer_business_number" maxlength="40" inputmode="numeric" dir="ltr" value="{{ old('signer_business_number', $contract['business_number']) }}">
                </label>
                <label class="ct-field">
                    <span>{{ __('sales.contract.address') }}</span>
                    <input type="text" name="signer_address" maxlength="191" autocomplete="street-address" value="{{ old('signer_address') }}">
                </label>
            </div>
            <div class="ct-field">
                <span>{{ __('sales.contract.draw') }}</span>
                <div class="ct-pad" data-ct-pad>
                    <canvas data-ct-canvas aria-label="{{ __('sales.contract.draw') }}"></canvas>
                    <button type="button" class="ct-pad__clear" data-ct-clear>{{ __('sales.contract.clear') }}</button>
                </div>
                <small class="ct-pad__error" data-ct-pad-error hidden>{{ __('sales.contract.errors.no_signature') }}</small>
            </div>
            <input type="hidden" name="signature" data-ct-signature>
            <label class="ct-agree">
                <input type="checkbox" name="agree" value="1" required>
                <span>{{ __('sales.contract.agree') }}</span>
            </label>
            <button type="submit" class="fin-btn fin-btn--primary ct-submit" data-ct-submit>{{ __('sales.contract.submit') }}</button>
        </form>
        <script src="{{ asset('js/contract-sign.js') }}?v={{ filemtime(public_path('js/contract-sign.js')) }}" defer></script>
    @endif

    @if($pdf)
        <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
        <script>
            // Loaded by the quote panel in a hidden frame: render the contract to a PDF and download it.
            (function () {
                var tell = function (ok) { try { parent.postMessage({ type: 'sq-pdf', ok: ok, blob: null }, location.origin); } catch (e) {} };
                (document.fonts ? document.fonts.ready : Promise.resolve()).then(function () {
                    return html2pdf().set({
                        margin: [10, 0, 10, 0],
                        filename: @json($filename.'.pdf'),
                        image: { type: 'jpeg', quality: 0.96 },
                        html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
                        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                        pagebreak: { mode: ['css', 'legacy'], avoid: ['tr', 'p', 'h3', '.ct-sign', '.fin-paper__totals'] }
                    }).from(document.querySelector('.fin-paper')).save();
                }).then(function () { tell(true); }, function () { tell(false); });
            })();
        </script>
    @endif
</body>
</html>
