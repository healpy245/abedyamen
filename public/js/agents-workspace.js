(function () {
    var KEY = 'kaman.agents.v2';
    var boot = window.AGENTS_BOOT || { page: 'leads', locale: 'en', user: '' };
    var loc = (document.documentElement.lang || boot.locale || 'en').slice(0, 2);
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var I18N = {
        en: {
            leads: 'Leads', calendar: 'Calendar', clients: 'Clients', agents: 'Sales Agents',
            new: 'New lead', contacted: 'Contacted', meeting: 'Meeting scheduled', demo: 'Demo completed', negotiation: 'Negotiation', won: 'Won', lost: 'Lost',
            scheduled: 'Scheduled', confirmed: 'Confirmed', done: 'Done', missed: 'Missed', cancelled: 'Cancelled',
            visit: 'Visit', call: 'Call', followup: 'Follow-up', onboarding: 'Onboarding',
            searchEmpty: 'Nothing matches.', create: 'Create', save: 'Save', cancel: 'Cancel', delete: 'Delete', edit: 'Edit',
            convert: 'Convert to client', openClient: 'Open client', schedule: 'Schedule', addNote: 'Add note', notePh: 'Write a note',
            newLead: 'New lead', newAppt: 'New appointment', newClient: 'New client',
            restaurant: 'Restaurant', contact: 'Contact', phone: 'Phone', city: 'City', agent: 'Agent', follow: 'Next follow-up',
            owner: 'Owner', address: 'Address', site: 'Website', notes: 'Notes', status: 'Status', services: 'Services', subscription: 'Subscription',
            last: 'Last activity', date: 'Date', time: 'Time', type: 'Type', all: 'All', any: 'Any time', today: 'Today', week: 'This week', overdue: 'Overdue',
            overview: 'Overview', features: 'Features', finance: 'Finance', support: 'Support', activity: 'Activity',
            monthly: 'Monthly', balance: 'Outstanding', nextPay: 'Next payment', invoices: 'Invoices', items: 'Billing items',
            paid: 'Paid', unpaid: 'Unpaid', trial: 'Trial', active: 'Active', paused: 'Paused',
            devices: 'Devices', online: 'Online', offline: 'Offline', heartbeat: 'Last heartbeat', logs: 'Recent logs', tickets: 'Tickets', version: 'Version',
            healthy: 'Healthy', partial: 'Partly offline', critical: 'Needs attention', none: 'No devices',
            readFinance: 'From MFITGROUP. Read only.', readSupport: 'From Support. Read only.', locked: 'Not in your access',
            included: 'Included', once: 'Once', each: 'Per message', perMonth: 'per month',
            toastSaved: 'Saved', toastFeature: 'Feature updated', toastDenied: 'You cannot change that', toastConverted: 'Client created',
            confirmDelete: 'Delete this lead?', confirmConvert: 'Mark it won and create the restaurant client?',
            yes: 'Confirm', no: 'Back', emptyNotes: 'No notes yet.', emptyAppt: 'No meetings yet.', emptyAct: 'No activity yet.',
            joined: 'Joined the client list', invoice: 'Invoice', ticket: 'Support ticket', featureOn: 'Feature turned on', featureOff: 'Feature turned off', meeting: 'Meeting', converted: 'Converted from a lead',
            board: 'Status', month: 'Month', day: 'Day', prev: 'Previous', next: 'Next', page: 'Page',
            since: 'Since', customer: 'Customer status', assigned: 'Assigned agent', enabled: 'Enabled features',
            namePh: 'Business name', required: 'Name and phone are required', pick: 'Choose a lead or client',
            noBilling: 'No billing on file yet.', noDevices: 'No devices are connected.', openTicket: 'Open ticket',
            filterAgent: 'Agent', filterCity: 'City', filterStatus: 'Status', filterWhen: 'Follow-up',
            pipeline: 'Sales pipeline', pipelineLead: 'Restaurants, follow-ups and new opportunities',
            leadDetails: 'Lead details', leadHint: 'Edit your opportunity without leaving the board',
            saveChanges: 'Save changes', allAgents: 'All agents', allCities: 'All cities', searchLead: 'Search restaurant or contact',
            weekView: 'Week',
            sun: 'Sun', mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri', sat: 'Sat',
            srcAgents: 'Sales Agents', srcFinance: 'Finance', srcSupport: 'Support'
        },
        he: {
            leads: 'לידים', calendar: 'לוח זמנים', clients: 'מסעדות', agents: 'סוכני מכירות',
            new: 'ליד חדש', contacted: 'נוצר קשר', meeting: 'פגישה נקבעה', demo: 'הדגמה הושלמה', negotiation: 'משא ומתן', won: 'נסגר', lost: 'אבוד',
            scheduled: 'מתוכנן', confirmed: 'מאושר', done: 'בוצע', missed: 'לא הגיע', cancelled: 'בוטל',
            visit: 'ביקור', call: 'שיחה', followup: 'מעקב', onboarding: 'הקמה',
            searchEmpty: 'אין התאמה.', create: 'יצירה', save: 'שמירה', cancel: 'ביטול', delete: 'מחיקה', edit: 'עריכה',
            convert: 'המרה ללקוח', openClient: 'פתיחת הלקוח', schedule: 'קביעת פגישה', addNote: 'הוספת הערה', notePh: 'כתבו הערה',
            newLead: 'ליד חדש', newAppt: 'פגישה חדשה', newClient: 'לקוח חדש',
            restaurant: 'מסעדה', contact: 'איש קשר', phone: 'טלפון', city: 'עיר', agent: 'סוכן', follow: 'המעקב הבא',
            owner: 'בעלים', address: 'כתובת', site: 'אתר', notes: 'הערות', status: 'סטטוס', services: 'שירותים', subscription: 'מנוי',
            last: 'פעילות אחרונה', date: 'תאריך', time: 'שעה', type: 'סוג', all: 'הכל', any: 'כל הזמנים', today: 'היום', week: 'השבוע', overdue: 'באיחור',
            overview: 'סקירה', features: 'שירותים', finance: 'כספים', support: 'תמיכה', activity: 'פעילות',
            monthly: 'חודשי', balance: 'יתרה', nextPay: 'תשלום הבא', invoices: 'חשבוניות', items: 'פריטי חיוב',
            paid: 'שולם', unpaid: 'לא שולם', trial: 'ניסיון', active: 'פעיל', paused: 'מושהה',
            devices: 'מכשירים', online: 'מחובר', offline: 'מנותק', heartbeat: 'פעימה אחרונה', logs: 'לוגים אחרונים', tickets: 'קריאות', version: 'גרסה',
            healthy: 'תקין', partial: 'חלק לא מחובר', critical: 'דורש טיפול', none: 'אין מכשירים',
            readFinance: 'מ-MFITGROUP. לקריאה בלבד.', readSupport: 'מהתמיכה. לקריאה בלבד.', locked: 'לא בהרשאה שלך',
            included: 'כלול', once: 'חד פעמי', each: 'להודעה', perMonth: 'לחודש',
            toastSaved: 'נשמר', toastFeature: 'השירות עודכן', toastDenied: 'אין הרשאה לשנות את זה', toastConverted: 'הלקוח נוצר',
            confirmDelete: 'למחוק את הליד?', confirmConvert: 'לסמן כנסגר וליצור לקוח מסעדה?',
            yes: 'אישור', no: 'חזרה', emptyNotes: 'אין הערות.', emptyAppt: 'אין פגישות.', emptyAct: 'אין פעילות.',
            joined: 'נכנס לרשימת הלקוחות', invoice: 'חשבונית', ticket: 'קריאת תמיכה', featureOn: 'שירות הופעל', featureOff: 'שירות כובה', meeting: 'פגישה', converted: 'הומר מליד',
            board: 'סטטוס', month: 'חודש', day: 'יום', prev: 'הקודם', next: 'הבא', page: 'עמוד',
            since: 'מאז', customer: 'סטטוס לקוח', assigned: 'סוכן משויך', enabled: 'שירותים פעילים',
            namePh: 'שם העסק', required: 'שם וטלפון חובה', pick: 'בחרו ליד או לקוח',
            noBilling: 'אין חיוב עדיין.', noDevices: 'אין מכשירים מחוברים.', openTicket: 'קריאה פתוחה',
            filterAgent: 'סוכן', filterCity: 'עיר', filterStatus: 'סטטוס', filterWhen: 'מעקב',
            pipeline: 'צינור מכירות', pipelineLead: 'מסעדות, מעקבים והזדמנויות חדשות',
            leadDetails: 'פרטי ליד', leadHint: 'עריכת ההזדמנות בלי לצאת מהלוח',
            saveChanges: 'שמירת שינויים', allAgents: 'כל הסוכנים', allCities: 'כל הערים', searchLead: 'חיפוש מסעדה או איש קשר',
            weekView: 'שבוע',
            sun: 'א׳', mon: 'ב׳', tue: 'ג׳', wed: 'ד׳', thu: 'ה׳', fri: 'ו׳', sat: 'ש׳',
            srcAgents: 'סוכנים', srcFinance: 'כספים', srcSupport: 'תמיכה'
        },
        ar: {
            leads: 'العملاء المحتملون', calendar: 'الجدول', clients: 'المطاعم', agents: 'وكلاء المبيعات',
            new: 'عميل جديد', contacted: 'تم التواصل', meeting: 'اجتماع محدد', demo: 'اكتمل العرض', negotiation: 'تفاوض', won: 'تم الفوز', lost: 'خسارة',
            scheduled: 'مجدول', confirmed: 'مؤكد', done: 'تم', missed: 'لم يحضر', cancelled: 'أُلغي',
            visit: 'زيارة', call: 'اتصال', followup: 'متابعة', onboarding: 'تشغيل',
            searchEmpty: 'لا توجد نتائج.', create: 'إنشاء', save: 'حفظ', cancel: 'إلغاء', delete: 'حذف', edit: 'تعديل',
            convert: 'تحويل إلى عميل', openClient: 'فتح العميل', schedule: 'جدولة', addNote: 'إضافة ملاحظة', notePh: 'اكتب ملاحظة',
            newLead: 'عميل محتمل', newAppt: 'موعد جديد', newClient: 'عميل جديد',
            restaurant: 'المطعم', contact: 'جهة الاتصال', phone: 'الهاتف', city: 'المدينة', agent: 'الوكيل', follow: 'المتابعة التالية',
            owner: 'المالك', address: 'العنوان', site: 'الموقع', notes: 'ملاحظات', status: 'الحالة', services: 'الخدمات', subscription: 'الاشتراك',
            last: 'آخر نشاط', date: 'التاريخ', time: 'الوقت', type: 'النوع', all: 'الكل', any: 'كل الأوقات', today: 'اليوم', week: 'هذا الأسبوع', overdue: 'متأخر',
            overview: 'نظرة عامة', features: 'الخدمات', finance: 'المالية', support: 'الدعم', activity: 'النشاط',
            monthly: 'شهري', balance: 'المستحق', nextPay: 'الدفعة التالية', invoices: 'الفواتير', items: 'بنود الفوترة',
            paid: 'مدفوع', unpaid: 'غير مدفوع', trial: 'تجريبي', active: 'نشط', paused: 'متوقف',
            devices: 'الأجهزة', online: 'متصل', offline: 'غير متصل', heartbeat: 'آخر نبضة', logs: 'السجلات', tickets: 'التذاكر', version: 'الإصدار',
            healthy: 'سليم', partial: 'انقطاع جزئي', critical: 'يحتاج معالجة', none: 'لا أجهزة',
            readFinance: 'من MFITGROUP. للقراءة فقط.', readSupport: 'من الدعم. للقراءة فقط.', locked: 'خارج صلاحيتك',
            included: 'مشمول', once: 'مرة واحدة', each: 'للرسالة', perMonth: '/ شهر',
            toastSaved: 'تم الحفظ', toastFeature: 'تم تحديث الخدمة', toastDenied: 'لا يمكنك تغيير هذا', toastConverted: 'تم إنشاء العميل',
            confirmDelete: 'حذف هذا العميل المحتمل؟', confirmConvert: 'تعليمه كفوز وإنشاء مطعم؟',
            yes: 'تأكيد', no: 'رجوع', emptyNotes: 'لا ملاحظات.', emptyAppt: 'لا اجتماعات.', emptyAct: 'لا نشاط.',
            joined: 'أُضيف إلى العملاء', invoice: 'فاتورة', ticket: 'تذكرة دعم', featureOn: 'تم تفعيل خدمة', featureOff: 'تم إيقاف خدمة', meeting: 'اجتماع', converted: 'حُوّل من عميل محتمل',
            board: 'الحالة', month: 'شهر', day: 'يوم', prev: 'السابق', next: 'التالي', page: 'صفحة',
            since: 'منذ', customer: 'حالة العميل', assigned: 'الوكيل', enabled: 'الخدمات المفعّلة',
            namePh: 'اسم العمل', required: 'الاسم والهاتف مطلوبان', pick: 'اختر عميلاً أو مطعماً',
            noBilling: 'لا فوترة بعد.', noDevices: 'لا أجهزة متصلة.', openTicket: 'تذكرة مفتوحة',
            filterAgent: 'الوكيل', filterCity: 'المدينة', filterStatus: 'الحالة', filterWhen: 'المتابعة',
            pipeline: 'مسار المبيعات', pipelineLead: 'مطاعم ومتابعات وفرص جديدة',
            leadDetails: 'تفاصيل العميل المحتمل', leadHint: 'عدّل الفرصة دون مغادرة اللوحة',
            saveChanges: 'حفظ التغييرات', allAgents: 'كل الوكلاء', allCities: 'كل المدن', searchLead: 'ابحث عن مطعم أو جهة اتصال',
            weekView: 'أسبوع',
            sun: 'أحد', mon: 'إثن', tue: 'ثلا', wed: 'أرب', thu: 'خمي', fri: 'جمع', sat: 'سبت',
            srcAgents: 'الوكلاء', srcFinance: 'المالية', srcSupport: 'الدعم'
        }
    };

    function t(key) {
        var pack = I18N[loc] || I18N.en;
        return pack[key] || I18N.en[key] || key;
    }

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function text(entry) {
        if (!entry) return '';
        if (typeof entry === 'string') return entry;
        return entry[loc] || entry.en || entry.he || entry.ar || '';
    }

    function nameBlob(entry) {
        if (!entry) return '';
        if (typeof entry === 'string') return entry;
        return (entry.en || '') + ' ' + (entry.he || '') + ' ' + (entry.ar || '');
    }

    function kindOf(entry) {
        var s = nameBlob(entry).toLowerCase();
        if (/shawarma|שווארמה|شاورما/.test(s)) return 'shawarma';
        if (/pizza|פיצה|بيتزا/.test(s)) return 'pizza';
        if (/sushi|סושי|سوشي/.test(s)) return 'sushi';
        if (/cafe|coffee|קפה|كافيه|مقهى/.test(s)) return 'cafe';
        if (/fish|דג|أسماك/.test(s)) return 'fish';
        if (/burger|בורגר|برغر/.test(s)) return 'burger';
        if (/bak|מאפ|مخبز|falafel|פלאפל|فلافل|hummus|חומוס|حمص/.test(s)) return 'bakery';
        return 'grill';
    }

    function photoSrc(entry) {
        if (entry && entry.logo) return entry.logo;
        if (entry && entry.name && entry.name.logo) return entry.name.logo;
        return (boot.images || '/images/agents') + '/' + kindOf(entry) + '.jpg';
    }

    function hostOf(url) {
        return String(url || '').replace(/^https?:\/\//, '').replace(/\/$/, '');
    }

    function logoHtml(entry) {
        var colors = ['#f47a2e', '#c45c26', '#8d4b2a', '#d4894a', '#a35b34', '#e07a3d', '#6e4630', '#b86a12'];
        var blob = nameBlob(entry);
        var hash = 0;
        for (var i = 0; i < blob.length; i++) hash = (hash * 33 + blob.charCodeAt(i)) >>> 0;
        var color = (entry && entry.color) || colors[hash % colors.length];
        var letter = (text(entry) || '?').trim().charAt(0);
        var badge = entry && entry.logo ? '' : '<b style="background:' + color + '">' + esc(letter) + '</b>';
        var klass = entry && entry.logo ? ' is-logo' : '';
        return '<span class="ag-logo' + klass + '" aria-hidden="true"><img src="' + esc(photoSrc(entry)) + '" alt="">' + badge + '</span>';
    }

    function pad(n) { return String(n).padStart(2, '0'); }

    function dayKey(date) {
        var d = new Date(date);
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }

    function formatDay(date) {
        if (!date) return '—';
        var d = new Date(date);
        var map = { en: 'en-GB', he: 'he-IL', ar: 'ar' };
        return d.toLocaleDateString(map[loc] || 'en-GB', { day: 'numeric', month: 'short' });
    }

    function formatTime(date) {
        var d = new Date(date);
        return pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function formatMoney(amount) {
        var n = Number(amount) || 0;
        if (n > 0 && n < 1) return '₪' + n.toFixed(2);
        return '₪' + Math.round(n).toLocaleString(loc === 'en' ? 'en-US' : 'he-IL');
    }

    function shift(days, hour) {
        var d = new Date();
        d.setDate(d.getDate() + days);
        d.setHours(hour, 0, 0, 0);
        return d.toISOString();
    }

    function toInput(iso) {
        var d = new Date(iso);
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    var CITIES = Array.isArray(boot.cities) && boot.cities.length ? boot.cities : [
        { id: 'naz', en: 'Nazareth', he: 'נצרת', ar: 'الناصرة' },
        { id: 'haifa', en: 'Haifa', he: 'חיפה', ar: 'حيفا' },
        { id: 'akko', en: 'Acre', he: 'עכו', ar: 'עكا' },
        { id: 'sak', en: 'Sakhnin', he: 'סחנין', ar: 'سخنين' },
        { id: 'tam', en: 'Tamra', he: 'טמרה', ar: 'طمرة' },
        { id: 'shef', en: 'Shefa-Amr', he: 'שפרעם', ar: 'شفاعمرو' },
        { id: 'umm', en: 'Umm al-Fahm', he: 'אום אל-פחם', ar: 'أم الفحم' },
        { id: 'jer', en: 'Jerusalem', he: 'ירושלים', ar: 'القدس' },
        { id: 'tlv', en: 'Tel Aviv', he: 'תל אביב', ar: 'تل أبيب' },
        { id: 'rah', en: 'Rahat', he: 'רהט', ar: 'رهط' },
        { id: 'baq', en: 'Baqa', he: 'באקה', ar: 'باقة' },
        { id: 'kan', en: 'Kafr Kanna', he: 'כפר כנא', ar: 'كفر كنا' },
        { id: 'arr', en: 'Arraba', he: 'עראבה', ar: 'عرابة' },
        { id: 'mag', en: 'Maghar', he: 'מגאר', ar: 'المغار' }
    ];

    var AGENTS = [
        { id: 'a1', name: 'Yamen Khoury' },
        { id: 'a2', name: 'Lina Mansour' },
        { id: 'a3', name: 'Omar Haddad' },
        { id: 'a4', name: 'Noor Awad' },
        { id: 'a5', name: 'Sara Cohen' }
    ];

    var CATALOG = [
        ['digital-menu', 149, 'monthly', false, 'Digital menu', 'תפריט דיגיטלי', 'قائمة رقمية', 'QR menu the guest orders from.', 'תפריט QR שהאורח מזמין ממנו.', 'قائمة QR يطلب منها الضيف.'],
        ['attendance', 79, 'monthly', false, 'Employee attendance', 'נוכחות עובדים', 'حضور الموظفين', 'Clock-in for the shift.', 'כניסה ויציאה למשמרת.', 'تسجيل الدخول للوردية.'],
        ['kiosk', 249, 'monthly', false, 'Kiosk station', 'עמדת קיוסק', 'محطة كشك', 'Self-order screen on site.', 'מסך הזמנה עצמית.', 'شاشة طلب ذاتي.'],
        ['inventory', 99, 'monthly', false, 'Inventory', 'מלאי', 'المخزون', 'Stock down to the recipe.', 'מלאי עד רמת המתכון.', 'مخزون حتى مستوى الوصفة.'],
        ['customers', 0, 'included', false, 'Customer data', 'נתוני לקוחות', 'بيانات الزبائن', 'Guest profiles on file.', 'פרופילי אורחים.', 'ملفات الزبائن.'],
        ['stickers', 390, 'once', false, 'Sticker printing', 'הדפסת מדבקות', 'طباعة ملصقات', 'One-time printer setup.', 'הקמת מדפסת חד פעמית.', 'تجهيز الطابعة لمرة واحدة.'],
        ['waiter', 129, 'monthly', false, 'Waiter system', 'מערכת מלצרים', 'نظام النُدل', 'Tables from a handheld.', 'שולחנות ממכשיר יד.', 'الطاولات من جهاز يدوي.'],
        ['sms', 0.09, 'each', false, 'SMS notifications', 'הודעות SMS', 'رسائل SMS', 'Charged per message.', 'חיוב לכל הודעה.', 'احتساب لكل رسالة.'],
        ['kds', 179, 'monthly', false, 'KDS', 'KDS', 'شاشة المطبخ', 'Kitchen display.', 'מסך מטבח.', 'شاشة المطبخ.'],
        ['haat', 0, 'included', false, 'HAAT', 'HAAT', 'HAAT', 'Orders land on the till.', 'הזמנות יורדות לקופה.', 'الطلبات تصل للصندوق.'],
        ['das', 0, 'included', true, 'DAS', 'DAS', 'DAS', 'Delivery link. Locked for agents.', 'קישור משלוחים. נעול לסוכן.', 'ربط التوصيل. مقفل للوكيل.'],
        ['wolt', 0, 'included', true, 'Wolt', 'Wolt', 'Wolt', 'Delivery link. Locked for agents.', 'קישור משלוחים. נעול לסוכן.', 'ربط التوصيل. مقفل للوكيل.'],
        ['qds', 99, 'monthly', false, 'QDS', 'QDS', 'شاشة الدور', 'Pickup queue screen.', 'מסך תור לאיסוף.', 'شاشة دور الاستلام.'],
        ['loyalty', 59, 'monthly', false, 'Loyalty', 'מועדון', 'الولاء', 'Club card and points.', 'כרטיס מועדון ונקודות.', 'بطاقة ولاء ونقاط.']
    ].map(function (row) {
        return {
            id: row[0], price: row[1], priceType: row[2], locked: row[3],
            name: { en: row[4], he: row[5], ar: row[6] },
            desc: { en: row[7], he: row[8], ar: row[9] }
        };
    });

    var PROSPECTS = [
        ['חומוס אבו שכר', 'Abu Shaker', 'حمص أبو شكر'],
        ['מסעדת אלואחה', 'Al Waha', 'مطعم الواحة'],
        ['פיצה לילה', 'Pizza Layla', 'بيتزا ليلة'],
        ['קפה אלנג׳מה', 'Al Najma', 'كافيه النجمة'],
        ['בורגר עכו', 'Akko Burger', 'برغر عكا'],
        ['שווארמה מגאר', 'Shawarma Mghar', 'شاورما المغار'],
        ['מסעדת הים', 'HaYam', 'مطعم البحر'],
        ['מאפה אלקלעה', 'Al Qalaa', 'مخبز القلعة'],
        ['ווק חיפה', 'Haifa Wok', 'ووك حيفا'],
        ['פלאפל יפו', 'Jaffa Falafel', 'فلافل يافا'],
        ['קפה באקה', 'Baqa Cafe', 'كافيه باقة'],
        ['מנגל שפרעם', 'Shefa Grill', 'مشاوي شفاعمرو'],
        ['סושי נצרת', 'Nazareth Sushi', 'سوشي الناصرة'],
        ['פיצה סכנין', 'Sakhnin Pizza', 'بيتزا سخنين'],
        ['מסעדת אלנהר', 'Al Nahr', 'مطعم النهر'],
        ['שווארמה ירושלים', 'Jerusalem Shawarma', 'شاورما القدس'],
        ['קפה עכו', 'Akko Cafe', 'كافيه عكا'],
        ['בשרים רהט', 'Rahat Grill', 'لحوم رهط'],
        ['מאפה טמרה', 'Tamra Bake', 'مخبز طمرة'],
        ['ווק תל אביב', 'TLV Wok', 'ووك تل أبيب'],
        ['פלאפל חיפה', 'Haifa Falafel', 'فلافل حيفا'],
        ['מסעדת אלביית', 'Al Bayt', 'مطعم البيت'],
        ['בורגר באקה', 'Baqa Burger', 'برغر باقة'],
        ['קפה מגאר', 'Mghar Cafe', 'كافيه المغار'],
        ['דגי נצרת', 'Nazareth Fish', 'أسماك الناصرة'],
        ['פיצה אום אל-פחם', 'Fahm Pizza', 'بيتزا أم الفحم'],
        ['שווארמה שפרעם', 'Shefa Shawarma', 'شاورما شفاعمرو'],
        ['מסעדת אלרמאן', 'Al Ruman', 'مطعم الرمان'],
        ['קפה כפר כנא', 'Kanna Cafe', 'كافيه كفر كنا'],
        ['מנגל עראבה', 'Arraba Grill', 'مشاوي عرابة'],
        ['סושי עכו', 'Akko Sushi', 'سوشي عكا'],
        ['מאפה ירושלים', 'Jerusalem Bake', 'مخبز القدس']
    ];

    var OWNERS = ['Ahmad', 'Rana', 'Mahmoud', 'Hanan', 'Yousef', 'Maya', 'Sami', 'Layla', 'Fadi', 'Nadine'];
    var STAGES = ['new', 'contacted', 'meeting', 'demo', 'negotiation', 'won', 'lost'];
    var LANE_COLOR = { new: '#5892ee', contacted: '#f58220', meeting: '#e6b349', demo: '#1baa82', negotiation: '#8b6bd7', won: '#128b66', lost: '#d96c3d' };
    var APPT_STATUS = ['scheduled', 'confirmed', 'done', 'missed', 'cancelled'];
    var LEAD_STAGES_OPEN = ['new', 'contacted', 'meeting', 'demo', 'negotiation', 'lost'];

    function phoneOf(n) {
        var prefixes = ['050', '052', '053', '054', '058'];
        var rest = String(1000000 + ((n * 137) % 9000000));
        return prefixes[n % 5] + '-' + rest.slice(0, 3) + '-' + rest.slice(3);
    }

    function tri(row) { return { he: row[0], en: row[1], ar: row[2] }; }

    function featureOnMap(index) {
        var on = {};
        CATALOG.forEach(function (feature, idx) {
            on[feature.id] = !feature.locked && ((index + idx) % 3) !== 0;
        });
        on['digital-menu'] = true;
        return on;
    }

    function hoursSummary(raw) {
        if (!raw) return '';
        var data;
        try { data = typeof raw === 'string' ? JSON.parse(raw) : raw; } catch (e) { return ''; }
        if (!data || typeof data !== 'object') return '';
        var order = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        var short = { Sunday: 'Sun', Monday: 'Mon', Tuesday: 'Tue', Wednesday: 'Wed', Thursday: 'Thu', Friday: 'Fri', Saturday: 'Sat' };
        var parts = [];
        order.forEach(function (day) {
            var slot = data[day] || {};
            if (!slot.from && !slot.to) return;
            parts.push((short[day] || day) + ' ' + (slot.from || '–') + '–' + (slot.to || '–'));
        });
        if (!parts.length) return '';
        var span = parts[0].slice(4);
        if (parts.every(function (part) { return part.slice(4) === span; })) return span;
        return parts.join(' · ');
    }

    function companyStamp(list) {
        return (list || []).map(function (company) {
            return (company.id || company.name) + ':' + (company.cityId || '');
        }).join('|');
    }

    function seed() {
        var restaurants = [];
        var features = {};
        var finance = {};
        var support = {};
        var activity = [];
        var leads = [];
        var appointments = [];
        var seq = 500;
        var companies = Array.isArray(boot.companies) ? boot.companies : [];

        companies.forEach(function (company, index) {
            var id = company.id || ('c-' + (index + 1));
            var address = company.address || '';
            var hours = hoursSummary(company.hours);
            var notes = [company.url || '', hours].filter(Boolean).join('\n');
            restaurants.push({
                id: id,
                name: { en: company.name, he: company.name, ar: company.name, logo: company.logo || '', color: company.color || '' },
                owner: '',
                phone: '',
                cityId: company.cityId || '',
                address: { en: address, he: address, ar: address },
                site: company.url || '',
                agentId: '',
                status: 'active',
                onboardedAt: null,
                notes: notes
            });
            features[id] = {};
            CATALOG.forEach(function (feature) { features[id][feature.id] = false; });
            finance[id] = { monthly: 0, nextPayment: null, items: [], invoices: [], plan: 'active' };
            support[id] = { kind: 'none', devices: [], logs: [], tickets: [] };
        });

        var prospect = 0;
        for (var n = 0; n < 40; n++) {
            var company = n < 8 ? companies[n] : null;
            var row = company ? [company.name, company.name, company.name] : PROSPECTS[prospect++ % PROSPECTS.length];
            var stage = company ? 'won' : LEAD_STAGES_OPEN[n % LEAD_STAGES_OPEN.length];
            var id = 'l-' + pad(n + 1);
            leads.push({
                id: id,
                name: tri(row),
                contact: company ? '' : OWNERS[n % OWNERS.length],
                phone: company ? '' : phoneOf(40 + n),
                cityId: company ? (company.cityId || '') : CITIES[n % CITIES.length].id,
                agentId: AGENTS[n % AGENTS.length].id,
                stage: stage,
                notes: [],
                restaurantId: company ? company.id : null,
                nextFollowUp: stage === 'lost' || stage === 'won' ? null : shift((n % 6) - 1, 11 + (n % 5))
            });
        }

        var types = ['visit', 'demo', 'call', 'followup', 'onboarding'];
        for (var a = 0; a < 20; a++) {
            var lead = leads[a % leads.length];
            appointments.push({
                id: 'p-' + pad(a + 1),
                leadId: lead.restaurantId ? null : lead.id,
                restaurantId: lead.restaurantId || null,
                agentId: lead.agentId,
                at: shift((a % 12) - 4, 9 + (a % 8)),
                type: types[a % types.length],
                status: APPT_STATUS[a % APPT_STATUS.length],
                notes: ''
            });
        }

        return {
            version: 2,
            companyStamp: companyStamp(companies),
            seq: seq,
            permissions: {
                viewRestaurant: true,
                manageCrm: true,
                manageFeatures: true,
                viewFinance: true,
                viewSupport: true,
                editFinance: false,
                editSupport: false,
                manageLockedFeatures: false
            },
            agents: AGENTS,
            restaurants: restaurants,
            features: features,
            finance: finance,
            support: support,
            activity: activity,
            leads: leads,
            appointments: appointments
        };
    }

    var state = null;
    var listeners = [];
    var channel = null;
    try { channel = new BroadcastChannel('kaman-agents'); } catch (e) { channel = null; }

    function emit() { listeners.forEach(function (fn) { fn(); }); }

    function persist() {
        try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) {}
        if (channel) channel.postMessage(state.seq);
    }

    function load() {
        var stamp = companyStamp(boot.companies);
        try {
            var raw = localStorage.getItem(KEY);
            if (raw) {
                var parsed = JSON.parse(raw);
                if (parsed && parsed.version === 2 && Array.isArray(parsed.restaurants) && (!stamp || parsed.companyStamp === stamp)) {
                    state = parsed;
                    return;
                }
            }
        } catch (e) {}
        state = seed();
        persist();
    }

    function guard(flag) {
        if (!state.permissions[flag]) {
            var error = new Error(flag);
            error.denied = true;
            throw error;
        }
    }

    function nextId(prefix) {
        state.seq += 1;
        return prefix + '-' + state.seq;
    }

    function touch(restaurantId, entry) {
        entry.id = nextId('e');
        entry.restaurantId = restaurantId;
        entry.at = entry.at || new Date().toISOString();
        state.activity.unshift(entry);
    }

    var restaurantRepository = {
        all: function () { return state.restaurants.slice(); },
        find: function (id) { return state.restaurants.find(function (row) { return row.id === id; }); },
        create: function (input) {
            guard('manageCrm');
            var id = nextId('r');
            var row = {
                id: id,
                name: { en: input.name, he: input.name, ar: input.name },
                owner: input.owner || input.contact || '',
                phone: input.phone || '',
                cityId: input.cityId,
                address: { en: input.address || '', he: input.address || '', ar: input.address || '' },
                agentId: input.agentId,
                status: 'onboarding',
                onboardedAt: new Date().toISOString(),
                notes: input.notes || ''
            };
            state.restaurants.unshift(row);
            state.features[id] = {};
            CATALOG.forEach(function (feature) { state.features[id][feature.id] = false; });
            state.finance[id] = { monthly: 0, nextPayment: null, items: [], invoices: [], plan: 'trial' };
            state.support[id] = { kind: 'none', devices: [], logs: [], tickets: [] };
            touch(id, { source: 'agents', key: 'joined' });
            persist();
            emit();
            return id;
        },
        update: function (id, patch) {
            guard('manageCrm');
            var row = restaurantRepository.find(id);
            if (!row) return;
            if (patch.contact != null && patch.owner == null) patch.owner = patch.contact;
            ['owner', 'phone', 'cityId', 'agentId', 'notes', 'status'].forEach(function (key) {
                if (patch[key] != null) row[key] = patch[key];
            });
            if (patch.address != null) row.address = { en: patch.address, he: patch.address, ar: patch.address };
            if (patch.name) row.name = { en: patch.name, he: patch.name, ar: patch.name };
            touch(id, { source: 'agents', key: 'joined' });
            persist();
            emit();
        }
    };

    var featuresRepository = {
        catalog: function () { return CATALOG; },
        forRestaurant: function (id) { return Object.assign({}, state.features[id] || {}); },
        set: function (id, featureId, on) {
            guard('manageFeatures');
            var feature = CATALOG.find(function (item) { return item.id === featureId; });
            if (!feature) return;
            if (feature.locked) guard('manageLockedFeatures');
            if (!state.features[id]) state.features[id] = {};
            state.features[id][featureId] = !!on;
            touch(id, { source: 'agents', key: on ? 'featureOn' : 'featureOff', vars: { feature: featureId } });
            persist();
            emit();
        }
    };

    var financeRepository = {
        forRestaurant: function (id) {
            guard('viewFinance');
            return state.finance[id] || { monthly: 0, nextPayment: null, items: [], invoices: [], plan: 'trial' };
        },
        update: function () { guard('editFinance'); }
    };

    var supportRepository = {
        forRestaurant: function (id) {
            guard('viewSupport');
            return state.support[id] || { kind: 'none', devices: [], logs: [], tickets: [] };
        },
        update: function () { guard('editSupport'); }
    };

    var leadRepository = {
        all: function () { return state.leads.slice(); },
        find: function (id) { return state.leads.find(function (row) { return row.id === id; }); },
        create: function (input) {
            guard('manageCrm');
            var id = nextId('l');
            state.leads.unshift({
                id: id,
                name: { en: input.name, he: input.name, ar: input.name },
                contact: input.contact || '',
                phone: input.phone || '',
                cityId: input.cityId,
                agentId: input.agentId,
                stage: input.stage || 'new',
                notes: [],
                restaurantId: null,
                nextFollowUp: input.nextFollowUp || null
            });
            persist();
            emit();
            return id;
        },
        update: function (id, patch) {
            guard('manageCrm');
            var row = leadRepository.find(id);
            if (!row) return;
            ['contact', 'phone', 'cityId', 'agentId', 'stage', 'nextFollowUp'].forEach(function (key) {
                if (patch[key] !== undefined) row[key] = patch[key];
            });
            if (patch.name) row.name = { en: patch.name, he: patch.name, ar: patch.name };
            persist();
            emit();
        },
        move: function (id, stage) {
            guard('manageCrm');
            var row = leadRepository.find(id);
            if (!row || STAGES.indexOf(stage) === -1) return;
            row.stage = stage;
            persist();
            emit();
        },
        note: function (id, text) {
            guard('manageCrm');
            var row = leadRepository.find(id);
            if (!row || !text) return;
            row.notes.unshift({ at: new Date().toISOString(), text: text });
            if (row.restaurantId) touch(row.restaurantId, { source: 'agents', key: 'meeting', vars: { name: text } });
            persist();
            emit();
        },
        remove: function (id) {
            guard('manageCrm');
            state.leads = state.leads.filter(function (row) { return row.id !== id; });
            state.appointments = state.appointments.filter(function (row) { return row.leadId !== id; });
            persist();
            emit();
        },
        convert: function (id) {
            guard('manageCrm');
            var row = leadRepository.find(id);
            if (!row) return null;
            row.stage = 'won';
            if (row.restaurantId && restaurantRepository.find(row.restaurantId)) {
                persist();
                emit();
                return row.restaurantId;
            }
            var restaurantId = restaurantRepository.create({
                name: text(row.name),
                owner: row.contact,
                phone: row.phone,
                cityId: row.cityId,
                agentId: row.agentId,
                address: '',
                notes: ''
            });
            row.restaurantId = restaurantId;
            touch(restaurantId, { source: 'agents', key: 'converted' });
            persist();
            emit();
            return restaurantId;
        }
    };

    var calendarRepository = {
        all: function () { return state.appointments.slice(); },
        find: function (id) { return state.appointments.find(function (row) { return row.id === id; }); },
        create: function (input) {
            guard('manageCrm');
            var id = nextId('p');
            state.appointments.unshift({
                id: id,
                leadId: input.leadId || null,
                restaurantId: input.restaurantId || null,
                agentId: input.agentId,
                at: input.at,
                type: input.type || 'visit',
                status: input.status || 'scheduled',
                notes: input.notes || ''
            });
            var restaurantId = input.restaurantId;
            if (!restaurantId && input.leadId) {
                var lead = leadRepository.find(input.leadId);
                if (lead) {
                    lead.nextFollowUp = input.at;
                    restaurantId = lead.restaurantId;
                }
            }
            if (restaurantId) touch(restaurantId, { source: 'agents', key: 'meeting' });
            persist();
            emit();
            return id;
        },
        update: function (id, patch) {
            guard('manageCrm');
            var row = calendarRepository.find(id);
            if (!row) return;
            ['leadId', 'restaurantId', 'agentId', 'at', 'type', 'status', 'notes'].forEach(function (key) {
                if (patch[key] !== undefined) row[key] = patch[key];
            });
            var restaurantId = row.restaurantId;
            if (!restaurantId && row.leadId) {
                var lead = leadRepository.find(row.leadId);
                restaurantId = lead && lead.restaurantId;
                if (lead) lead.nextFollowUp = row.at;
            }
            if (restaurantId) touch(restaurantId, { source: 'agents', key: 'meeting' });
            persist();
            emit();
        }
    };

    load();
    if (channel) {
        channel.onmessage = function () { load(); emit(); };
    }
    window.addEventListener('storage', function (event) {
        if (event.key === KEY) { load(); emit(); }
    });

    var ui = {
        page: boot.page || 'leads',
        q: '',
        booting: !reduce,
        leadId: null,
        leadDraft: false,
        restaurantId: null,
        editing: false,
        tab: 'overview',
        apptId: null,
        confirm: null,
        filters: { agent: '', city: '', stage: '', when: '', billing: '' },
        cal: 'month',
        cursor: new Date(),
        calAgent: '',
        pageNum: 1,
        sort: 'name',
        sortDir: 1,
        bell: false
    };

    var view = document.getElementById('ag-view');
    var toastEl = document.getElementById('ag-toast');
    var toastTimer = 0;
    var suppressClick = false;

    function toast(message) {
        toastEl.textContent = message;
        toastEl.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.hidden = true; }, 2200);
    }

    function cityById(id) { return CITIES.find(function (city) { return city.id === id; }); }
    function agentById(id) { return AGENTS.find(function (agent) { return agent.id === id; }); }

    function enabledCount(id) {
        var map = state.features[id] || {};
        return Object.keys(map).filter(function (key) { return map[key]; }).length;
    }

    function lastActivity(id) {
        var rows = state.activity.filter(function (row) { return row.restaurantId === id; });
        if (!rows.length) return null;
        return rows.slice().sort(function (a, b) { return new Date(b.at) - new Date(a.at); })[0].at;
    }

    function activityText(row) {
        if (row.key === 'invoice') return t('invoice') + ' ' + (row.vars && row.vars.number ? row.vars.number : '') + ' · ' + t(row.vars && row.vars.status || 'paid');
        if (row.key === 'ticket') return t('ticket') + ' · ' + ((row.vars && row.vars.title) || '');
        if (row.key === 'featureOn' || row.key === 'featureOff') {
            var feature = CATALOG.find(function (item) { return item.id === (row.vars && row.vars.feature); });
            return t(row.key) + (feature ? ' · ' + text(feature.name) : '');
        }
        return t(row.key || 'joined');
    }

    function sourceLabel(source) {
        if (source === 'finance') return t('srcFinance');
        if (source === 'support') return t('srcSupport');
        return t('srcAgents');
    }

    function priceLabel(feature) {
        if (feature.priceType === 'included' || feature.price === 0) return t('included');
        if (feature.priceType === 'once') return formatMoney(feature.price) + ' ' + t('once');
        if (feature.priceType === 'each') return formatMoney(feature.price) + ' ' + t('each');
        return formatMoney(feature.price) + ' ' + t('perMonth');
    }

    function matchQuery(parts) {
        if (!ui.q) return true;
        var q = ui.q.toLowerCase();
        return parts.join(' ').toLowerCase().indexOf(q) !== -1;
    }

    function nextFollow(lead) {
        var times = [];
        if (lead.nextFollowUp) times.push(new Date(lead.nextFollowUp).getTime());
        state.appointments.forEach(function (row) {
            if (row.leadId === lead.id && new Date(row.at) >= new Date()) times.push(new Date(row.at).getTime());
        });
        if (!times.length) return null;
        return new Date(Math.min.apply(null, times)).toISOString();
    }

    function whenMatch(lead) {
        var mode = ui.filters.when;
        if (!mode) return true;
        var next = nextFollow(lead);
        if (!next) return false;
        var day = dayKey(next);
        var now = new Date();
        if (mode === 'today') return day === dayKey(now);
        if (mode === 'overdue') return new Date(next) < now && day !== dayKey(now);
        var end = new Date(now);
        end.setDate(now.getDate() + 7);
        var start = new Date(now);
        start.setHours(0, 0, 0, 0);
        return new Date(next) <= end && new Date(next) >= start;
    }

    function filteredLeads() {
        return state.leads.filter(function (lead) {
            if (ui.filters.agent && lead.agentId !== ui.filters.agent) return false;
            if (ui.filters.city && lead.cityId !== ui.filters.city) return false;
            if (ui.filters.stage && lead.stage !== ui.filters.stage) return false;
            if (!whenMatch(lead)) return false;
            var city = cityById(lead.cityId);
            var agent = agentById(lead.agentId);
            return matchQuery([text(lead.name), lead.contact, lead.phone, text(city), agent ? agent.name : '']);
        });
    }

    function apptTitle(row) {
        if (row.restaurantId) {
            var restaurant = restaurantRepository.find(row.restaurantId);
            return restaurant ? text(restaurant.name) : row.restaurantId;
        }
        var lead = leadRepository.find(row.leadId);
        return lead ? text(lead.name) : '';
    }

    function apptContact(row) {
        if (row.restaurantId) {
            var restaurant = restaurantRepository.find(row.restaurantId);
            return restaurant ? restaurant.owner : '';
        }
        var lead = leadRepository.find(row.leadId);
        return lead ? lead.contact : '';
    }

    function selectAgents(selected) {
        return AGENTS.map(function (agent) {
            return '<option value="' + agent.id + '"' + (agent.id === selected ? ' selected' : '') + '>' + esc(agent.name) + '</option>';
        }).join('');
    }

    function selectCities(selected) {
        return CITIES.map(function (city) {
            return '<option value="' + city.id + '"' + (city.id === selected ? ' selected' : '') + '>' + esc(text(city)) + '</option>';
        }).join('');
    }

    function options(list, selected) {
        return list.map(function (key) {
            return '<option value="' + key + '"' + (key === selected ? ' selected' : '') + '>' + esc(t(key)) + '</option>';
        }).join('');
    }

    function renderLeads() {
        var leads = filteredLeads();
        var stages = ui.filters.stage ? [ui.filters.stage] : STAGES;
        var columns = stages.map(function (stage) {
            var rows = leads.filter(function (lead) { return lead.stage === stage; });
            var cards = rows.map(function (lead) {
                var city = text(cityById(lead.cityId));
                var who = [lead.contact, city].filter(Boolean).join(' · ');
                return '<button type="button" class="sales-card" draggable="true" data-lead="' + lead.id + '" data-act="open-lead" data-id="' + lead.id + '">' +
                    '<img class="sales-photo" src="' + esc(photoSrc(lead.name)) + '" alt="">' +
                    '<span class="sales-card-info"><strong>' + esc(text(lead.name)) + '</strong>' +
                    '<p>' + esc(who || '—') + '</p>' +
                    '<span class="sales-card-bottom"><span>' + esc((agentById(lead.agentId) || {}).name || '—') + '</span><span>' + esc(lead.phone || '—') + '</span></span></span></button>';
            }).join('');
            return '<section class="sales-lane" data-stage="' + stage + '" style="--lane-color:' + (LANE_COLOR[stage] || '#f58220') + '"><div class="sales-lane-head"><span>' + esc(t(stage)) + '</span><small>' + rows.length + '</small></div>' + cards + '</section>';
        }).join('');
        return '<div class="sales-head"><div><h1>' + esc(t('pipeline')) + '</h1><p>' + esc(t('pipelineLead')) + '</p></div>' +
            '<button type="button" class="sales-btn sales-btn--primary" data-act="new-lead">+ ' + esc(t('newLead')) + '</button></div>' +
            '<div class="sales-filters"><label class="sales-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="M20 20l-3.5-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>' +
            '<input data-sales-search value="' + esc(ui.q) + '" placeholder="' + esc(t('searchLead')) + '" aria-label="' + esc(t('searchLead')) + '"></label>' +
            '<select data-filter="agent" aria-label="' + esc(t('filterAgent')) + '"><option value="">' + esc(t('allAgents')) + '</option>' + selectAgents(ui.filters.agent) + '</select>' +
            '<select data-filter="city" aria-label="' + esc(t('filterCity')) + '"><option value="">' + esc(t('allCities')) + '</option>' + selectCities(ui.filters.city) + '</select>' +
            '<select data-filter="stage" aria-label="' + esc(t('filterStatus')) + '"><option value="">' + esc(t('filterStatus')) + '</option>' + options(STAGES, ui.filters.stage) + '</select>' +
            '<select data-filter="when" aria-label="' + esc(t('filterWhen')) + '"><option value="">' + esc(t('any')) + '</option><option value="today"' + (ui.filters.when === 'today' ? ' selected' : '') + '>' + esc(t('today')) + '</option><option value="week"' + (ui.filters.when === 'week' ? ' selected' : '') + '>' + esc(t('week')) + '</option><option value="overdue"' + (ui.filters.when === 'overdue' ? ' selected' : '') + '>' + esc(t('overdue')) + '</option></select></div>' +
            '<div class="sales-board">' + columns + '</div>';
    }

    function toolbar(act, label) {
        return '<div class="ag-toolbar">' +
            '<button type="button" class="ag-btn" data-act="' + act + '">' + esc(label) + '</button>' +
            '<select data-filter="agent" aria-label="' + esc(t('filterAgent')) + '"><option value="">' + esc(t('all')) + '</option>' + selectAgents(ui.filters.agent) + '</select>' +
            '<select data-filter="city" aria-label="' + esc(t('filterCity')) + '"><option value="">' + esc(t('all')) + '</option>' + selectCities(ui.filters.city) + '</select>' +
            (ui.page === 'leads'
                ? '<select data-filter="stage" aria-label="' + esc(t('filterStatus')) + '"><option value="">' + esc(t('all')) + '</option>' + options(STAGES, ui.filters.stage) + '</select>' +
                  '<select data-filter="when" aria-label="' + esc(t('filterWhen')) + '"><option value="">' + esc(t('any')) + '</option><option value="today"' + (ui.filters.when === 'today' ? ' selected' : '') + '>' + esc(t('today')) + '</option><option value="week"' + (ui.filters.when === 'week' ? ' selected' : '') + '>' + esc(t('week')) + '</option><option value="overdue"' + (ui.filters.when === 'overdue' ? ' selected' : '') + '>' + esc(t('overdue')) + '</option></select>'
                : '') +
            '</div>';
    }

    function sameDay(a, b) { return dayKey(a) === dayKey(b); }

    function apptsOn(date) {
        return state.appointments.filter(function (row) {
            if (ui.calAgent && row.agentId !== ui.calAgent) return false;
            if (!sameDay(row.at, date)) return false;
            return matchQuery([apptTitle(row), apptContact(row), (agentById(row.agentId) || {}).name || '']);
        });
    }

    function apptCard(row) {
        var place = row.restaurantId ? (restaurantRepository.find(row.restaurantId) || {}).name : (leadRepository.find(row.leadId) || {}).name;
        return '<button type="button" class="ag-appt" draggable="true" data-appt="' + row.id + '" data-act="open-appt" data-id="' + row.id + '">' +
            '<span class="ag-card-id">' + logoHtml(place) + '<strong>' + esc(apptTitle(row)) + '</strong></span>' +
            '<p>' + esc(apptContact(row)) + '</p>' +
            '<footer><span>' + esc(formatDay(row.at)) + ' ' + esc(formatTime(row.at)) + '</span><span><i class="ag-dot ' + row.status + '"></i> ' + esc((agentById(row.agentId) || {}).name || '') + '</span></footer>' +
            '</button>';
    }

    function renderCalendar() {
        var agentFilter = '<select data-cal-agent aria-label="' + esc(t('agent')) + '"><option value="">' + esc(t('all')) + '</option>' + selectAgents(ui.calAgent) + '</select>';
        if (ui.cal === 'board') ui.cal = 'month';
        var modes = ['month', 'week', 'day'].map(function (mode) {
            return '<button type="button" data-act="cal-mode" data-mode="' + mode + '" class="' + (ui.cal === mode ? 'is-on' : '') + '">' + esc(t(mode === 'week' ? 'weekView' : mode)) + '</button>';
        }).join('');
        var nav = '<div class="ag-toolbar"><button type="button" class="ag-btn" data-act="new-appt">' + esc(t('newAppt')) + '</button>' +
            '<div class="ag-cal-switch">' + modes + '</div>' +
            '<button type="button" class="ag-btn ghost" data-act="cal-move" data-dir="-1">' + esc(t('prev')) + '</button>' +
            '<button type="button" class="ag-btn ghost" data-act="cal-today">' + esc(t('today')) + '</button>' +
            '<button type="button" class="ag-btn ghost" data-act="cal-move" data-dir="1">' + esc(t('next')) + '</button>' +
            agentFilter + '</div>';
        if (ui.cal === 'day') {
            var hours = '';
            for (var h = 8; h <= 20; h++) {
                var hits = state.appointments.filter(function (row) {
                    var d = new Date(row.at);
                    return sameDay(d, ui.cursor) && d.getHours() === h && (!ui.calAgent || row.agentId === ui.calAgent);
                });
                hours += '<div class="ag-hour"><span>' + pad(h) + ':00</span><div>' + hits.map(apptCard).join('') + '</div></div>';
            }
            return nav + '<div class="ag-hours">' + hours + '</div>';
        }
        var days = [];
        if (ui.cal === 'week') {
            var start = new Date(ui.cursor);
            start.setDate(start.getDate() - start.getDay());
            for (var i = 0; i < 7; i++) {
                var day = new Date(start);
                day.setDate(start.getDate() + i);
                days.push(day);
            }
            var week = days.map(function (day) {
                return '<button type="button" class="ag-week-col' + (sameDay(day, new Date()) ? ' is-today' : '') + '" data-act="new-appt-day" data-day="' + dayKey(day) + '"><b>' + esc(['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'].map(t)[day.getDay()]) + ' ' + day.getDate() + '</b>' + apptsOn(day).map(function (row) {
                    return '<span class="ag-chip" draggable="true" data-appt="' + row.id + '" data-act="open-appt" data-id="' + row.id + '">' + esc(formatTime(row.at)) + ' ' + esc(apptTitle(row)) + '</span>';
                }).join('') + '</button>';
            }).join('');
            return nav + '<div class="ag-week">' + week + '</div>';
        }
        var first = new Date(ui.cursor.getFullYear(), ui.cursor.getMonth(), 1);
        var gridStart = new Date(first);
        gridStart.setDate(1 - first.getDay());
        var names = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'].map(function (key) { return '<b>' + esc(t(key)) + '</b>'; }).join('');
        var cells = '';
        for (var g = 0; g < 42; g++) {
            var cell = new Date(gridStart);
            cell.setDate(gridStart.getDate() + g);
            var outside = cell.getMonth() !== ui.cursor.getMonth();
            cells += '<button type="button" class="ag-day' + (outside ? ' is-out' : '') + (sameDay(cell, new Date()) ? ' is-today' : '') + '" data-act="new-appt-day" data-day="' + dayKey(cell) + '"><b>' + cell.getDate() + '</b>' +
                apptsOn(cell).slice(0, 3).map(function (row) {
                    return '<span class="ag-chip" draggable="true" data-appt="' + row.id + '" data-act="open-appt" data-id="' + row.id + '">' + esc(formatTime(row.at)) + ' ' + esc(apptTitle(row)) + '</span>';
                }).join('') + '</button>';
        }
        return nav + '<div class="ag-month" style="margin-bottom:6px">' + names + '</div><div class="ag-month">' + cells + '</div>';
    }

    function filteredClients() {
        var rows = state.restaurants.filter(function (row) {
            if (ui.filters.agent && row.agentId !== ui.filters.agent) return false;
            if (ui.filters.city && row.cityId !== ui.filters.city) return false;
            if (ui.filters.stage && row.status !== ui.filters.stage) return false;
            var billing = (state.finance[row.id] || {}).plan || 'active';
            if (ui.filters.billing && billing !== ui.filters.billing && !((state.finance[row.id] || {}).invoices || []).some(function (inv) { return inv.status === ui.filters.billing; })) return false;
            return matchQuery([text(row.name), row.owner, row.phone, row.site, text(cityById(row.cityId)), (agentById(row.agentId) || {}).name || '']);
        });
        rows.sort(function (a, b) {
            var av = ui.sort === 'city' ? text(cityById(a.cityId)) : ui.sort === 'status' ? a.status : ui.sort === 'last' ? (lastActivity(a.id) || '') : text(a.name);
            var bv = ui.sort === 'city' ? text(cityById(b.cityId)) : ui.sort === 'status' ? b.status : ui.sort === 'last' ? (lastActivity(b.id) || '') : text(b.name);
            return av < bv ? -ui.sortDir : av > bv ? ui.sortDir : 0;
        });
        return rows;
    }

    function renderClients() {
        var rows = filteredClients();
        var per = 8;
        var pages = Math.max(1, Math.ceil(rows.length / per));
        if (ui.pageNum > pages) ui.pageNum = pages;
        var slice = rows.slice((ui.pageNum - 1) * per, ui.pageNum * per);
        var head = ['name', 'city', 'status', 'last'].map(function (key) {
            var label = key === 'name' ? t('restaurant') : key === 'city' ? t('city') : key === 'status' ? t('status') : t('last');
            return '<th data-act="sort" data-key="' + key + '">' + esc(label) + '</th>';
        }).join('');
        var body = slice.map(function (row) {
            var fin = state.finance[row.id] || {};
            var invoices = fin.invoices || [];
            var billing = invoices.some(function (inv) { return inv.status === 'overdue'; }) ? 'overdue'
                : invoices.some(function (inv) { return inv.status === 'unpaid'; }) ? 'unpaid'
                : invoices.length ? 'paid'
                : (fin.plan === 'trial' ? 'trial' : '');
            var extra = row.owner || hostOf(row.site);
            var place = text(cityById(row.cityId)) || hostOf(row.site);
            return '<tr data-act="open-client" data-id="' + row.id + '"><td><span class="ag-who">' + logoHtml(row.name) + '<span><strong>' + esc(text(row.name)) + '</strong><br><span>' + esc(extra) + '</span></span></span></td>' +
                '<td>' + esc(place) + '<br>' + esc(row.phone) + '</td>' +
                '<td><span class="ag-pill">' + enabledCount(row.id) + '</span> <span class="ag-pill ' + row.status + '">' + esc(t(row.status)) + '</span>' + (billing ? ' <span class="ag-pill ' + billing + '">' + esc(t(billing)) + '</span>' : '') + '</td>' +
                '<td>' + esc((agentById(row.agentId) || {}).name || '') + '<br>' + esc(formatDay(lastActivity(row.id))) + '</td></tr>';
        }).join('');
        var pager = '';
        for (var p = 1; p <= pages; p++) pager += '<button type="button" data-act="page" data-n="' + p + '" class="' + (p === ui.pageNum ? 'is-on' : '') + '">' + p + '</button>';
        var filters = '<div class="ag-toolbar"><button type="button" class="ag-btn" data-act="new-client">' + esc(t('newClient')) + '</button>' +
            '<select data-filter="agent"><option value="">' + esc(t('all')) + '</option>' + selectAgents(ui.filters.agent) + '</select>' +
            '<select data-filter="city"><option value="">' + esc(t('all')) + '</option>' + selectCities(ui.filters.city) + '</select>' +
            '<select data-filter="stage"><option value="">' + esc(t('all')) + '</option>' + options(['active', 'onboarding', 'paused'], ui.filters.stage) + '</select>' +
            '<select data-filter="billing"><option value="">' + esc(t('subscription')) + '</option>' + options(['paid', 'unpaid', 'overdue', 'trial'], ui.filters.billing) + '</select></div>';
        if (!rows.length) return filters + '<div class="ag-empty">' + esc(t('searchEmpty')) + '</div>';
        return filters + '<div class="ag-table-wrap"><table class="ag-table"><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table></div><div class="ag-pager"><span>' + esc(t('page')) + '</span>' + pager + '</div>';
    }

    function copy(key) {
        return (boot.copy && boot.copy[key]) || key;
    }

    function restaurantRow(row) {
        var city = text(cityById(row.cityId));
        var agent = (agentById(row.agentId) || {}).name || '—';
        var logo = (row.name && row.name.logo) || row.logo || '';
        var mark = logo
            ? '<img class="rest-card__logo" src="' + esc(logo) + '" alt="' + esc(text(row.name)) + '">'
            : '<span class="rest-card__logo rest-card__logo--mark" aria-hidden="true">' + esc((text(row.name) || '?').charAt(0)) + '</span>';
        return '<button type="button" class="rest-card" data-act="open-client" data-id="' + row.id + '">' +
            '<span class="rest-card__top">' + mark +
            '<span><span class="rest-card__name">' + esc(text(row.name)) + '</span>' +
            '<span class="rest-card__city">' + esc(city || hostOf(row.site) || '—') + '</span></span></span>' +
            '<span class="rest-card__foot"><span class="rest-card__status' + (row.status === 'active' ? '' : ' is-pending') + '">' + esc(t(row.status)) + '</span>' +
            '<span>' + esc(agent) + ' ↗</span></span></button>';
    }

    function statCard(label, count, hint) {
        return '<article><div class="stat-top"><span>' + esc(label) + '</span></div><strong>' + esc(pad(count)) + '</strong><small>' + esc(hint) + '</small></article>';
    }

    function renderDashboard() {
        var links = boot.links || {};
        var leads = state.leads.filter(function (row) { return row.stage !== 'won' && row.stage !== 'lost'; }).length;
        var preview = state.restaurants.slice(0, 4).map(restaurantRow).join('');
        var activity = (boot.activity || []).map(function (item) {
            return '<div class="activity-item"><span class="activity-icon ' + esc(item.tone || '') + '"></span><div><strong>' + esc(item.title) + '</strong><small>' + esc(item.meta) + '</small></div><time>' + esc(item.time) + '</time></div>';
        }).join('');
        return '<div class="overview-heading"><div><span class="eyebrow">' + esc(copy('eyebrow')) + '</span><h1>' + esc(copy('title')) + '</h1><p>' + esc(copy('lead')) + '</p></div>' +
            '<a class="sales-btn" data-ag-nav="restaurants" href="' + esc(links.restaurants || '#') + '">' + esc(copy('browse')) + '</a></div>' +
            '<div class="overview-stats">' +
            statCard(copy('statRestaurants'), state.restaurants.length, copy('restaurants')) +
            statCard(copy('statLeads'), leads, copy('sales')) +
            statCard(copy('statTickets'), (boot.stats && boot.stats.tickets) || 0, copy('support')) +
            statCard(copy('statTasks'), (boot.stats && boot.stats.tasks) || 0, copy('development')) +
            '</div><div class="overview-panels">' +
            '<section class="overview-panel"><div class="panel-heading"><div><h2>' + esc(copy('restaurants')) + '</h2><p>' + esc(copy('restaurantsLead')) + '</p></div>' +
            '<a class="text-action" data-ag-nav="restaurants" href="' + esc(links.restaurants || '#') + '">' + esc(copy('viewAll')) + '</a></div>' +
            (preview || '<p class="overview-empty">' + esc(copy('empty')) + '</p>') + '</section>' +
            '<section class="overview-panel"><div class="panel-heading"><div><h2>' + esc(copy('activity')) + '</h2></div></div>' +
            (activity || '<p class="overview-empty">' + esc(copy('empty')) + '</p>') +
            '<div class="quick-access"><h3>' + esc(copy('quick')) + '</h3><div>' +
            '<a href="' + esc(links.agents || '#') + '">' + esc(copy('sales')) + '</a>' +
            '<a href="' + esc(links.support || '#') + '">' + esc(copy('support')) + '</a>' +
            '<a href="' + esc(links.development || '#') + '">' + esc(copy('development')) + '</a>' +
            '</div></div></section></div>';
    }

    function renderDirectory() {
        var q = ui.q.trim().toLowerCase();
        var rows = state.restaurants.filter(function (row) {
            if (ui.filters.city && row.cityId !== ui.filters.city) return false;
            if (ui.filters.stage && row.status !== ui.filters.stage) return false;
            if (!q) return true;
            var blob = (text(row.name) + ' ' + text(cityById(row.cityId)) + ' ' + (row.owner || '') + ' ' + (row.phone || '')).toLowerCase();
            return blob.indexOf(q) !== -1;
        });
        var links = boot.links || {};
        var boarding = links.boarding || 'https://boarding.mfitgroup.com';
        return '<div class="rest-head"><div><h1>' + esc(copy('restaurants')) + '</h1><p>' + esc(copy('restaurantsLead')) + '</p></div>' +
            '<a class="rest-add" href="' + esc(boarding) + '" target="_blank" rel="noopener noreferrer">+ ' + esc(copy('add')) + '</a></div>' +
            '<div class="directory-controls"><label class="sales-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="M20 20l-3.5-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>' +
            '<input data-sales-search value="' + esc(ui.q) + '" placeholder="' + esc(t('searchLead')) + '" aria-label="' + esc(t('searchLead')) + '"></label>' +
            '<select data-filter="city" aria-label="' + esc(t('filterCity')) + '"><option value="">' + esc(t('allCities')) + '</option>' + selectCities(ui.filters.city) + '</select>' +
            '<select data-filter="stage" aria-label="' + esc(t('filterStatus')) + '"><option value="">' + esc(copy('allStatuses') || t('all')) + '</option>' + options(['active', 'onboarding', 'paused'], ui.filters.stage) + '</select></div>' +
            (rows.length ? '<div class="rest-grid">' + rows.map(restaurantRow).join('') + '</div>' : '<p class="overview-empty">' + esc(t('searchEmpty')) + '</p>');
    }

    function renderView() {
        if (ui.booting) {
            if (boot.surface === 'overview' && ui.page !== 'restaurants') return;
            view.innerHTML = '<div class="ag-skel"><i></i><i></i><i></i></div>';
            return;
        }
        var searchEl = document.querySelector('[data-sales-search]');
        var focused = !!(searchEl && document.activeElement === searchEl);
        var pos = focused ? searchEl.selectionStart : null;
        if (boot.surface === 'overview') {
            var hub = document.getElementById('hub-scene');
            if (ui.page === 'restaurants') {
                if (hub) hub.hidden = true;
                view.hidden = false;
                view.innerHTML = renderDirectory();
            } else {
                if (hub) hub.hidden = false;
                view.hidden = true;
                view.innerHTML = '';
            }
        }
        else if (ui.page === 'calendar') view.innerHTML = renderCalendar();
        else view.innerHTML = renderLeads();
        if (focused) {
            var nextSearch = document.querySelector('[data-sales-search]');
            if (nextSearch) {
                nextSearch.focus();
                if (pos != null) nextSearch.setSelectionRange(pos, pos);
            }
        }
    }

    function formFields(values) {
        return '<label class="ag-field">' + esc(t('restaurant')) + '<input name="name" required value="' + esc(values.name || '') + '" placeholder="' + esc(t('namePh')) + '"></label>' +
            '<label class="ag-field">' + esc(t('contact')) + '<input name="contact" value="' + esc(values.contact || '') + '"></label>' +
            '<label class="ag-field">' + esc(t('phone')) + '<input name="phone" value="' + esc(values.phone || '') + '"></label>' +
            '<label class="ag-field">' + esc(t('city')) + '<select name="cityId">' + selectCities(values.cityId) + '</select></label>' +
            '<label class="ag-field">' + esc(t('agent')) + '<select name="agentId">' + selectAgents(values.agentId) + '</select></label>';
    }

    function renderLead() {
        if (!ui.leadId && !ui.leadDraft) return '';
        var lead = ui.leadId ? leadRepository.find(ui.leadId) : null;
        if (ui.leadId && !lead) { ui.leadId = null; return ''; }
        var values = lead ? { name: text(lead.name), contact: lead.contact, phone: lead.phone, cityId: lead.cityId, agentId: lead.agentId, stage: lead.stage, next: lead.nextFollowUp ? toInput(lead.nextFollowUp) : '' } : { agentId: 'a1', cityId: CITIES[0].id, stage: 'new', next: '' };
        var notes = lead ? lead.notes.map(function (note) {
            return '<div class="ag-note"><strong>' + esc(formatDay(note.at)) + '</strong><div>' + esc(note.text) + '</div></div>';
        }).join('') : '';
        var meetings = lead ? state.appointments.filter(function (row) { return row.leadId === lead.id; }).map(function (row) {
            return '<button type="button" class="ag-line" data-act="open-appt" data-id="' + row.id + '"><span>' + esc(formatDay(row.at)) + ' ' + esc(formatTime(row.at)) + ' · ' + esc(t(row.type)) + '</span><span class="ag-pill">' + esc(t(row.status)) + '</span></button>';
        }).join('') : '';
        var title = lead ? t('leadDetails') : t('newLead');
        var identity = lead ? text(lead.name) : t('newLead');
        var sub = lead ? (t(lead.stage) + (text(cityById(lead.cityId)) ? ' · ' + text(cityById(lead.cityId)) : '')) : t('pipelineLead');
        return '<div class="sales-modal" data-dialog role="document"><div class="sales-modal-head"><div><h2 id="ag-portal-title">' + esc(title) + '</h2><p>' + esc(t('leadHint')) + '</p></div><button type="button" class="sales-modal-close" data-act="close" aria-label="' + esc(t('cancel')) + '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button></div>' +
            '<div class="sales-modal-body"><div class="sales-identity"><div class="sales-identity-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg></div><div><strong>' + esc(identity) + '</strong><small>' + esc(sub) + '</small></div></div>' +
            '<form id="ag-lead-form" data-form="lead" class="sales-form">' +
            '<label>' + esc(t('restaurant')) + '<input name="name" required value="' + esc(values.name || '') + '" placeholder="' + esc(t('namePh')) + '"></label>' +
            '<label>' + esc(t('contact')) + '<input name="contact" value="' + esc(values.contact || '') + '"></label>' +
            '<label>' + esc(t('phone')) + '<input name="phone" required value="' + esc(values.phone || '') + '"></label>' +
            '<label>' + esc(t('city')) + '<select name="cityId">' + selectCities(values.cityId) + '</select></label>' +
            '<label>' + esc(t('agent')) + '<select name="agentId">' + selectAgents(values.agentId) + '</select></label>' +
            '<label>' + esc(t('status')) + '<select name="stage">' + options(STAGES, values.stage) + '</select></label>' +
            '<label class="sales-full">' + esc(t('follow')) + '<input type="datetime-local" name="nextFollowUp" value="' + esc(values.next || '') + '"></label></form>' +
            (lead ? '<div class="sales-notes"><h3>' + esc(t('notes')) + '</h3>' + (notes ? notes.replace(/ag-note/g, 'sales-note') : '<p class="sales-empty-note">' + esc(t('emptyNotes')) + '</p>') +
                (meetings ? '<div class="sales-meetings">' + meetings + '</div>' : '') +
                '<form data-form="note" data-id="' + lead.id + '"><textarea name="text" placeholder="' + esc(t('notePh')) + '"></textarea><button class="sales-btn" type="submit">' + esc(t('addNote')) + '</button></form></div>' : '') +
            '</div><div class="sales-modal-foot">' +
            (lead ? '<button type="button" class="sales-btn sales-delete" data-act="ask-delete" data-id="' + lead.id + '">' + esc(t('delete')) + '</button>' : '<span></span>') +
            '<div>' +
            (lead ? '<button type="button" class="sales-btn" data-act="schedule-lead" data-id="' + lead.id + '">' + esc(t('schedule')) + '</button>' : '') +
            (lead && lead.restaurantId ? '<button type="button" class="sales-btn" data-act="open-client" data-id="' + lead.restaurantId + '">' + esc(t('openClient')) + '</button>' : '') +
            (lead && !lead.restaurantId ? '<button type="button" class="sales-btn" data-act="ask-convert" data-id="' + lead.id + '">' + esc(t('convert')) + '</button>' : '') +
            '<button type="submit" class="sales-btn sales-btn--primary" form="ag-lead-form">' + esc(t('saveChanges')) + '</button></div></div></div>';
    }

    function renderRestaurant() {
        if (!ui.restaurantId) return '';
        var row = restaurantRepository.find(ui.restaurantId);
        if (!row) { ui.restaurantId = null; return ''; }
        var tabs = ['overview', 'features', 'finance', 'support', 'activity'].map(function (tab) {
            return '<button type="button" data-act="tab" data-tab="' + tab + '" class="' + (ui.tab === tab ? 'is-on' : '') + '">' + esc(t(tab)) + '</button>';
        }).join('');
        var body = '';
        if (ui.tab === 'overview') {
            if (ui.editing) {
                body = '<form data-form="client"><div class="ag-grid-2">' + formFields({ name: text(row.name), contact: row.owner, phone: row.phone, cityId: row.cityId, agentId: row.agentId }) +
                    '<label class="ag-field">' + esc(t('customer')) + '<select name="status">' + options(['active', 'onboarding', 'paused'], row.status) + '</select></label>' +
                    '<label class="ag-field">' + esc(t('address')) + '<input name="address" value="' + esc(text(row.address)) + '"></label></div>' +
                    '<label class="ag-field">' + esc(t('notes')) + '<textarea name="notes">' + esc(row.notes || '') + '</textarea></label>' +
                    '<div class="ag-actions"><button class="ag-btn" type="submit">' + esc(t('save')) + '</button><button class="ag-btn ghost" type="button" data-act="cancel-edit">' + esc(t('cancel')) + '</button></div></form>';
            } else {
                body = '<div class="ag-grid-2">' +
                    kv(t('owner'), row.owner) + kv(t('phone'), row.phone) + kv(t('city'), text(cityById(row.cityId))) + kv(t('address'), text(row.address)) +
                    (row.site ? kv(t('site'), row.site) : '') +
                    kv(t('assigned'), (agentById(row.agentId) || {}).name || '') + kv(t('customer'), t(row.status)) +
                    kv(t('since'), formatDay(row.onboardedAt)) + kv(t('enabled'), String(enabledCount(row.id))) +
                    '</div><p class="ag-notes">' + esc(row.notes || t('emptyNotes')) + '</p><button type="button" class="ag-btn ghost" data-act="edit-client">' + esc(t('edit')) + '</button>';
            }
        } else if (ui.tab === 'features') {
            var map = featuresRepository.forRestaurant(row.id);
            body = '<div class="ag-features">' + CATALOG.map(function (feature) {
                var on = !!map[feature.id];
                return '<article class="ag-feature"><div><strong>' + esc(text(feature.name)) + '</strong><p>' + esc(text(feature.desc)) + '</p><em>' + esc(priceLabel(feature)) + (feature.locked ? ' · ' + t('locked') : '') + '</em></div>' +
                    '<button type="button" class="ag-switch' + (on ? ' is-on' : '') + '" data-act="toggle-feature" data-id="' + row.id + '" data-feature="' + feature.id + '" data-on="' + (on ? '1' : '0') + '" aria-pressed="' + (on ? 'true' : 'false') + '"' + (feature.locked ? ' disabled' : '') + '><i></i></button></article>';
            }).join('') + '</div>';
        } else if (ui.tab === 'finance') {
            var fin = financeRepository.forRestaurant(row.id);
            var outstanding = (fin.invoices || []).filter(function (inv) { return inv.status !== 'paid'; }).reduce(function (sum, inv) { return sum + inv.amount; }, 0);
            body = '<p class="ag-sub">' + esc(t('readFinance')) + '</p><div class="ag-money"><article><span>' + esc(t('monthly')) + '</span><strong>' + esc(formatMoney(fin.monthly)) + '</strong></article><article><span>' + esc(t('balance')) + '</span><strong>' + esc(formatMoney(outstanding)) + '</strong></article><article><span>' + esc(t('nextPay')) + '</span><strong>' + esc(fin.nextPayment ? formatDay(fin.nextPayment) : '—') + '</strong></article></div>' +
                '<h3>' + esc(t('items')) + '</h3>' + ((fin.items || []).map(function (item) {
                    var feature = CATALOG.find(function (f) { return f.id === item.featureId; });
                    return '<div class="ag-line"><span>' + esc(feature ? text(feature.name) : item.featureId) + '</span><span>' + esc(formatMoney(item.amount)) + ' · ' + esc(t(item.priceType === 'monthly' ? 'perMonth' : item.priceType === 'once' ? 'once' : 'each')) + '</span></div>';
                }).join('') || '<p class="ag-sub">' + esc(t('noBilling')) + '</p>') +
                '<h3>' + esc(t('invoices')) + '</h3>' + ((fin.invoices || []).map(function (inv) {
                    return '<div class="ag-line"><span>' + esc(inv.number) + ' · ' + esc(formatDay(inv.issued)) + '</span><span class="ag-pill ' + inv.status + '">' + esc(t(inv.status)) + ' ' + esc(formatMoney(inv.amount)) + '</span></div>';
                }).join('') || '<p class="ag-sub">' + esc(t('noBilling')) + '</p>');
        } else if (ui.tab === 'support') {
            var sup = supportRepository.forRestaurant(row.id);
            var online = (sup.devices || []).filter(function (device) { return device.online; }).length;
            var lastBeat = (sup.devices || []).map(function (device) { return device.lastSeen; }).sort().pop();
            body = '<p class="ag-sub">' + esc(t('readSupport')) + '</p><div class="ag-health"><i class="ag-dot ' + sup.kind + '"></i> ' + esc(t(sup.kind)) + '</div>' +
                '<div class="ag-money"><article><span>' + esc(t('devices')) + '</span><strong>' + (sup.devices || []).length + '</strong></article><article><span>' + esc(t('online')) + '</span><strong>' + online + '</strong></article><article><span>' + esc(t('offline')) + '</span><strong>' + ((sup.devices || []).length - online) + '</strong></article></div>' +
                '<p class="ag-sub">' + esc(t('heartbeat')) + ' · ' + esc(lastBeat ? formatDay(lastBeat) + ' ' + formatTime(lastBeat) : '—') + '</p>' +
                '<div class="ag-devices">' + ((sup.devices || []).map(function (device) {
                    return '<div class="ag-device"><span><i class="ag-dot ' + (device.online ? 'healthy' : 'critical') + '"></i> ' + esc(device.name) + '</span><span>' + esc(device.version) + '</span></div>';
                }).join('') || '<p class="ag-sub">' + esc(t('noDevices')) + '</p>') + '</div>' +
                '<h3>' + esc(t('logs')) + '</h3>' + (sup.logs || []).map(function (log) {
                    return '<div class="ag-line"><span>' + esc(log.message) + '</span><span>' + esc(formatDay(log.at)) + '</span></div>';
                }).join('') +
                '<h3>' + esc(t('tickets')) + '</h3>' + ((sup.tickets || []).map(function (ticket) {
                    return '<div class="ag-line"><span>' + esc(ticket.title) + '</span><span class="ag-pill">' + esc(t('openTicket')) + '</span></div>';
                }).join('') || '<p class="ag-sub">—</p>');
        } else {
            var events = state.activity.filter(function (item) { return item.restaurantId === row.id; }).sort(function (a, b) { return new Date(b.at) - new Date(a.at); });
            body = '<div class="ag-timeline">' + (events.map(function (item) {
                return '<article><time>' + esc(formatDay(item.at)) + '</time><div><span class="ag-src">' + esc(sourceLabel(item.source)) + '</span><div>' + esc(activityText(item)) + '</div></div></article>';
            }).join('') || '<p class="ag-sub">' + esc(t('emptyAct')) + '</p>') + '</div>';
        }
        return '<div class="ag-modal" data-dialog role="document"><div class="ag-hero' + (row.name && row.name.logo ? ' is-logo' : '') + '"><img src="' + esc(photoSrc(row.name)) + '" alt="">' + logoHtml(row.name) + '</div><header><div><h2 id="ag-portal-title">' + esc(text(row.name)) + '</h2><p class="ag-sub">' + esc(t(row.status)) + (row.site ? ' · ' + esc(hostOf(row.site)) : '') + ' · ' + enabledCount(row.id) + ' ' + esc(t('services')) + '</p></div><button type="button" class="ag-x" data-act="close" aria-label="' + esc(t('cancel')) + '">×</button></header><div class="ag-tabs">' + tabs + '</div><div class="ag-modal-body">' + body + '</div></div>';
    }

    function kv(label, value) {
        return '<div class="ag-kv"><span>' + esc(label) + '</span><b>' + esc(value || '—') + '</b></div>';
    }

    function renderAppt() {
        if (!ui.apptId) return '';
        var row = ui.apptId === 'new' ? { agentId: ui.calAgent || 'a1', at: ui.apptAt || shift(0, 10), type: 'visit', status: 'scheduled', notes: '', leadId: ui.apptLead || '', restaurantId: '' } : calendarRepository.find(ui.apptId);
        if (!row) { ui.apptId = null; return ''; }
        var choices = state.leads.map(function (lead) {
            return '<option value="lead:' + lead.id + '"' + (row.leadId === lead.id ? ' selected' : '') + '>' + esc(text(lead.name)) + '</option>';
        }).join('') + state.restaurants.map(function (restaurant) {
            return '<option value="restaurant:' + restaurant.id + '"' + (row.restaurantId === restaurant.id ? ' selected' : '') + '>' + esc(text(restaurant.name)) + '</option>';
        }).join('');
        return '<form class="ag-dialog" data-dialog data-form="appt" role="document"><h2 id="ag-portal-title">' + esc(ui.apptId === 'new' ? t('newAppt') : apptTitle(row)) + '</h2>' +
            '<label class="ag-field">' + esc(t('restaurant')) + '<select name="target" required>' + choices + '</select></label>' +
            '<label class="ag-field">' + esc(t('agent')) + '<select name="agentId">' + selectAgents(row.agentId) + '</select></label>' +
            '<label class="ag-field">' + esc(t('date')) + '<input type="datetime-local" name="at" required value="' + esc(toInput(row.at)) + '"></label>' +
            '<div class="ag-grid-2"><label class="ag-field">' + esc(t('type')) + '<select name="type">' + options(['visit', 'demo', 'call', 'followup', 'onboarding'], row.type) + '</select></label>' +
            '<label class="ag-field">' + esc(t('status')) + '<select name="status">' + options(APPT_STATUS, row.status) + '</select></label></div>' +
            '<label class="ag-field">' + esc(t('notes')) + '<textarea name="notes">' + esc(row.notes || '') + '</textarea></label>' +
            '<div class="ag-actions"><button class="ag-btn" type="submit">' + esc(t('save')) + '</button><button class="ag-btn ghost" type="button" data-act="close">' + esc(t('cancel')) + '</button></div></form>';
    }

    function renderConfirm() {
        if (!ui.confirm) return '';
        var copy = ui.confirm.kind === 'delete-lead' ? t('confirmDelete') : t('confirmConvert');
        return '<div class="ag-dialog" data-dialog role="document"><h2 id="ag-portal-title">' + esc(copy) + '</h2><div class="ag-actions"><button type="button" class="ag-btn" data-act="confirm-yes">' + esc(t('yes')) + '</button><button type="button" class="ag-btn ghost" data-act="close">' + esc(t('no')) + '</button></div></div>';
    }

    function renderClientForm() {
        if (!ui.clientDraft) return '';
        return '<form class="ag-dialog" data-dialog data-form="new-client" role="document"><h2 id="ag-portal-title">' + esc(t('newClient')) + '</h2><div class="ag-grid-2">' + formFields({ agentId: 'a1', cityId: CITIES[0].id }) + '</div><div class="ag-actions"><button class="ag-btn" type="submit">' + esc(t('create')) + '</button><button class="ag-btn ghost" type="button" data-act="close">' + esc(t('cancel')) + '</button></div></form>';
    }

    function topKind() {
        if (ui.confirm) return 'confirm';
        if (ui.apptId) return 'appt';
        if (ui.clientDraft) return 'client';
        if (ui.restaurantId) return 'restaurant';
        if (ui.leadId || ui.leadDraft) return 'lead';
        return '';
    }

    function renderLayer() {
        var dialog = document.getElementById('ag-portal');
        var kind = topKind();
        var html = kind === 'confirm' ? renderConfirm()
            : kind === 'appt' ? renderAppt()
            : kind === 'client' ? renderClientForm()
            : kind === 'restaurant' ? renderRestaurant()
            : kind === 'lead' ? renderLead()
            : '';
        if (dialog) {
            dialog.classList.toggle('ag-portal--wide', kind === 'restaurant');
            dialog.classList.toggle('ag-portal--lead', kind === 'lead');
            dialog.classList.toggle('ag-portal--form', kind === 'appt' || kind === 'client' || kind === 'confirm');
            if (!html) {
                if (dialog.open) {
                    dialog.dataset.sync = '1';
                    dialog.close();
                    dialog.dataset.sync = '';
                }
                dialog.innerHTML = '';
            } else {
                var scroller = dialog.querySelector('.sales-modal-body, .ag-modal-body');
                var top = scroller ? scroller.scrollTop : 0;
                var active = document.activeElement;
                var restore = active && dialog.contains(active) ? (active.getAttribute('name') || '') : '';
                dialog.innerHTML = html;
                if (!dialog.open) dialog.showModal();
                var next = dialog.querySelector('.sales-modal-body, .ag-modal-body');
                if (next) next.scrollTop = top;
                if (restore) {
                    var field = dialog.querySelector('[name="' + restore + '"]');
                    if (field) field.focus();
                }
            }
        }
        var bell = document.querySelector('[data-ag-bell-panel]');
        if (bell) {
            bell.hidden = !ui.bell;
            var latest = state.activity.slice().sort(function (a, b) { return new Date(b.at) - new Date(a.at); }).slice(0, 8);
            bell.innerHTML = latest.map(function (item) {
                var restaurant = restaurantRepository.find(item.restaurantId);
                return '<button type="button" data-act="open-client" data-id="' + item.restaurantId + '">' + esc(restaurant ? text(restaurant.name) : '') + '<small>' + esc(activityText(item)) + '</small></button>';
            }).join('');
        }
        var bellBtn = document.querySelector('[data-ag-bell]');
        if (bellBtn) bellBtn.setAttribute('aria-expanded', ui.bell ? 'true' : 'false');
    }

    function syncChrome() {
        document.querySelectorAll('[data-ag-nav]').forEach(function (link) {
            var on = link.getAttribute('data-ag-nav') === ui.page;
            link.classList.toggle('is-on', on);
            link.classList.toggle('is-selected', on);
            if (on) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
        var floatNav = document.querySelector('[data-ws-float]');
        var indicator = floatNav && floatNav.querySelector('[data-ws-indicator]');
        var active = floatNav && floatNav.querySelector('.is-selected');
        if (indicator && active) {
            indicator.style.width = active.offsetWidth + 'px';
            indicator.style.transform = 'translateX(' + (active.offsetLeft - 6) + 'px)';
        }
        var title = document.querySelector('[data-ag-title]');
        if (title) title.textContent = boot.surface === 'overview' ? (ui.page === 'restaurants' ? copy('restaurants') : copy('title')) : t(ui.page);
        document.title = (boot.surface === 'overview'
            ? (ui.page === 'restaurants' ? copy('restaurants') : copy('title'))
            : t(ui.page) + ' — ' + t('agents'));
    }

    function render() {
        syncChrome();
        renderView();
        renderLayer();
    }

    function closeTop() {
        if (ui.confirm) { ui.confirm = null; render(); return; }
        if (ui.apptId) { ui.apptId = null; ui.apptLead = null; render(); return; }
        if (ui.clientDraft) { ui.clientDraft = false; render(); return; }
        if (ui.restaurantId) { ui.restaurantId = null; ui.editing = false; render(); return; }
        ui.leadId = null;
        ui.leadDraft = false;
        render();
    }

    function safe(fn) {
        try { fn(); }
        catch (error) {
            if (error && error.denied) toast(t('toastDenied'));
            else throw error;
        }
    }

    function readForm(form) {
        var data = {};
        new FormData(form).forEach(function (value, key) { data[key] = value; });
        return data;
    }

    document.addEventListener('click', function (event) {
        if (suppressClick) { suppressClick = false; return; }
        var collapse = event.target.closest('[data-ag-collapse]');
        if (collapse) {
            document.documentElement.classList.toggle('ag-collapsed');
            try { localStorage.setItem('kaman.agents.sidebar', document.documentElement.classList.contains('ag-collapsed') ? '1' : '0'); } catch (e) {}
            return;
        }
        var bell = event.target.closest('[data-ag-bell]');
        if (bell) { ui.bell = !ui.bell; renderLayer(); return; }
        if (!event.target.closest('[data-ag-bell-panel]') && !bell) ui.bell = false;
        var nav = event.target.closest('[data-ag-nav]');
        if (nav && !event.metaKey && !event.ctrlKey && !event.shiftKey && event.button === 0) {
            event.preventDefault();
            ui.page = nav.getAttribute('data-ag-nav');
            ui.pageNum = 1;
            history.pushState({}, '', nav.href);
            render();
            return;
        }
        var actEl = event.target.closest('[data-act]');
        if (!actEl) { if (ui.bell) { ui.bell = false; renderLayer(); } return; }
        if (actEl.dataset.act === 'close-back' && event.target !== actEl) return;
        var act = actEl.dataset.act;
        var id = actEl.dataset.id;
        if (act === 'close' || act === 'close-back') { closeTop(); return; }
        if (act === 'open-lead') { ui.leadId = id; ui.leadDraft = false; ui.bell = false; render(); return; }
        if (act === 'new-lead') { ui.leadId = null; ui.leadDraft = true; render(); return; }
        if (act === 'open-client') { ui.restaurantId = id; ui.tab = 'overview'; ui.editing = false; ui.bell = false; render(); return; }
        if (act === 'new-client') { ui.clientDraft = true; render(); return; }
        if (act === 'edit-client') { ui.editing = true; render(); return; }
        if (act === 'cancel-edit') { ui.editing = false; render(); return; }
        if (act === 'tab') { ui.tab = actEl.dataset.tab; ui.editing = false; render(); return; }
        if (act === 'toggle-feature') {
            safe(function () {
                featuresRepository.set(id, actEl.dataset.feature, actEl.dataset.on !== '1');
                toast(t('toastFeature'));
            });
            return;
        }
        if (act === 'ask-delete') { ui.confirm = { kind: 'delete-lead', id: id }; render(); return; }
        if (act === 'ask-convert') { ui.confirm = { kind: 'convert', id: id }; render(); return; }
        if (act === 'confirm-yes' && ui.confirm) {
            var pending = ui.confirm;
            ui.confirm = null;
            safe(function () {
                if (pending.kind === 'delete-lead') { leadRepository.remove(pending.id); ui.leadId = null; toast(t('toastSaved')); }
                if (pending.kind === 'convert') {
                    var restaurantId = leadRepository.convert(pending.id);
                    ui.leadId = null;
                    ui.restaurantId = restaurantId;
                    ui.tab = 'overview';
                    toast(t('toastConverted'));
                }
                render();
            });
            return;
        }
        if (act === 'open-appt') { ui.apptId = id; render(); return; }
        if (act === 'new-appt' || act === 'schedule-lead') {
            ui.apptId = 'new';
            ui.apptLead = act === 'schedule-lead' ? id : '';
            ui.apptAt = shift(1, 10);
            render();
            return;
        }
        if (act === 'new-appt-day') {
            if (event.target.closest('[data-appt]')) return;
            ui.apptId = 'new';
            ui.apptAt = actEl.dataset.day + 'T10:00';
            render();
            return;
        }
        if (act === 'cal-mode') { ui.cal = actEl.dataset.mode; render(); return; }
        if (act === 'cal-today') { ui.cursor = new Date(); render(); return; }
        if (act === 'cal-move') {
            var dir = Number(actEl.dataset.dir);
            var cursor = new Date(ui.cursor);
            if (ui.cal === 'month') cursor.setMonth(cursor.getMonth() + dir);
            else if (ui.cal === 'week') cursor.setDate(cursor.getDate() + (7 * dir));
            else cursor.setDate(cursor.getDate() + dir);
            ui.cursor = cursor;
            render();
            return;
        }
        if (act === 'sort') {
            if (ui.sort === actEl.dataset.key) ui.sortDir *= -1;
            else { ui.sort = actEl.dataset.key; ui.sortDir = 1; }
            render();
            return;
        }
        if (act === 'page') { ui.pageNum = Number(actEl.dataset.n); render(); }
    });

    document.addEventListener('change', function (event) {
        var filter = event.target.getAttribute('data-filter');
        if (filter) {
            ui.filters[filter] = event.target.value;
            ui.pageNum = 1;
            render();
            return;
        }
        if (event.target.hasAttribute('data-cal-agent')) {
            ui.calAgent = event.target.value;
            render();
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('form[data-form]');
        if (!form) return;
        event.preventDefault();
        var data = readForm(form);
        var kind = form.dataset.form;
        safe(function () {
            if (kind === 'lead') {
                if (!data.name || !data.phone) { toast(t('required')); return; }
                var patch = { name: data.name, contact: data.contact, phone: data.phone, cityId: data.cityId, agentId: data.agentId, stage: data.stage, nextFollowUp: data.nextFollowUp ? new Date(data.nextFollowUp).toISOString() : null };
                if (ui.leadDraft) { ui.leadId = leadRepository.create(patch); ui.leadDraft = false; }
                else leadRepository.update(ui.leadId, patch);
                toast(t('toastSaved'));
            } else if (kind === 'note') {
                leadRepository.note(form.dataset.id, data.text);
                toast(t('toastSaved'));
            } else if (kind === 'client') {
                restaurantRepository.update(ui.restaurantId, data);
                ui.editing = false;
                toast(t('toastSaved'));
            } else if (kind === 'new-client') {
                if (!data.name || !data.phone) { toast(t('required')); return; }
                ui.restaurantId = restaurantRepository.create(data);
                ui.clientDraft = false;
                ui.tab = 'overview';
                toast(t('toastSaved'));
            } else if (kind === 'appt') {
                var target = data.target || '';
                var payload = { agentId: data.agentId, at: new Date(data.at).toISOString(), type: data.type, status: data.status, notes: data.notes, leadId: null, restaurantId: null };
                if (target.indexOf('lead:') === 0) payload.leadId = target.slice(5);
                if (target.indexOf('restaurant:') === 0) payload.restaurantId = target.slice(11);
                if (!payload.leadId && !payload.restaurantId) { toast(t('pick')); return; }
                if (ui.apptId === 'new') calendarRepository.create(payload);
                else calendarRepository.update(ui.apptId, payload);
                ui.apptId = null;
                toast(t('toastSaved'));
            }
            render();
        });
    });

    document.addEventListener('dragstart', function (event) {
        var card = event.target.closest('[data-lead], [data-appt]');
        if (!card) return;
        suppressClick = false;
        event.dataTransfer.setData('text/plain', (card.dataset.lead ? 'lead:' : 'appt:') + (card.dataset.lead || card.dataset.appt));
        event.dataTransfer.effectAllowed = 'move';
    });

    document.addEventListener('dragend', function () { suppressClick = true; });

    document.addEventListener('dragover', function (event) {
        var col = event.target.closest('[data-stage], [data-appt-status], [data-day]');
        if (!col) return;
        event.preventDefault();
        col.classList.add('is-over');
    });

    document.addEventListener('dragleave', function (event) {
        var col = event.target.closest('[data-stage], [data-appt-status], [data-day]');
        if (col) col.classList.remove('is-over');
    });

    document.addEventListener('drop', function (event) {
        var col = event.target.closest('[data-stage], [data-appt-status], [data-day]');
        if (!col) return;
        event.preventDefault();
        col.classList.remove('is-over');
        var raw = event.dataTransfer.getData('text/plain');
        safe(function () {
            if (raw.indexOf('lead:') === 0 && col.dataset.stage) leadRepository.move(raw.slice(5), col.dataset.stage);
            if (raw.indexOf('appt:') === 0 && col.dataset.apptStatus) calendarRepository.update(raw.slice(5), { status: col.dataset.apptStatus });
            if (raw.indexOf('appt:') === 0 && col.dataset.day) {
                var current = calendarRepository.find(raw.slice(5));
                if (!current) return;
                var prev = new Date(current.at);
                var next = new Date(col.dataset.day + 'T' + pad(prev.getHours()) + ':' + pad(prev.getMinutes()));
                calendarRepository.update(current.id, { at: next.toISOString() });
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            var portal = document.getElementById('ag-portal');
            if (portal && portal.open) return;
            closeTop();
            return;
        }
        if (event.key === 'Enter' && event.target.closest('[data-act="open-lead"], [data-act="open-client"]')) event.target.click();
        if (event.key !== 'Tab') return;
        var dialog = document.querySelector('[data-dialog]');
        if (!dialog) return;
        var items = dialog.querySelectorAll('button, input, select, textarea, a');
        if (!items.length) return;
        var first = items[0];
        var last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });

    var search = document.querySelector('[data-ag-search]');
    if (search) {
        search.addEventListener('input', function () {
            ui.q = search.value;
            ui.pageNum = 1;
            renderView();
        });
    }

    document.addEventListener('input', function (event) {
        if (!event.target.matches('[data-sales-search]')) return;
        ui.q = event.target.value;
        ui.pageNum = 1;
        if (search && search !== event.target) search.value = event.target.value;
        renderView();
    });

    var portal = document.getElementById('ag-portal');
    if (portal) {
        portal.addEventListener('cancel', function (event) {
            event.preventDefault();
            closeTop();
        });
        portal.addEventListener('click', function (event) {
            if (event.target === portal) closeTop();
        });
    }

    window.addEventListener('popstate', function () {
        var part = location.pathname.split('/').filter(Boolean).pop();
        if (boot.surface === 'overview') ui.page = part === 'restaurants' ? 'restaurants' : 'dashboard';
        else ui.page = part === 'calendar' ? 'calendar' : 'leads';
        render();
    });

    listeners.push(function () { render(); });
    render();
    if (ui.booting) setTimeout(function () { ui.booting = false; render(); }, 160);

    window.KamanAgents = {
        restaurantRepository: restaurantRepository,
        leadRepository: leadRepository,
        calendarRepository: calendarRepository,
        featuresRepository: featuresRepository,
        financeRepository: financeRepository,
        supportRepository: supportRepository
    };
})();
