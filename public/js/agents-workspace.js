(function () {
    var KEY = 'kaman.agents.v2';
    var IMPORT_KEY = 'kaman.agents.import';
    var boot = window.AGENTS_BOOT || { page: 'leads', locale: 'en', user: '' };
    var loc = (document.documentElement.lang || boot.locale || 'en').slice(0, 2);
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var I18N = {
        en: {
            leads: 'Leads', calendar: 'Calendar', clients: 'Clients', agents: 'Sales Agents',
            new: 'New lead', contacted: 'Contacted', meeting: 'Meeting scheduled', demo: 'Demo completed', negotiation: 'Negotiation', won: 'Won', lost: 'Lost',
            markDone: 'Mark as done', scheduled: 'Scheduled', confirmed: 'Confirmed', done: 'Done', missed: 'Missed', cancelled: 'Cancelled',
            visit: 'Visit', call: 'Call', followup: 'Follow-up', onboarding: 'Onboarding',
            searchEmpty: 'Nothing matches.', create: 'Create', save: 'Save', cancel: 'Cancel', delete: 'Delete', edit: 'Edit',
            convert: 'Convert to client', openClient: 'Open client', schedule: 'Schedule', addNote: 'Add note', notePh: 'Write a note',
            newLead: 'New lead', newAppt: 'New appointment', newClient: 'New client',
            restaurant: 'Restaurant', contact: 'Contact', phone: 'Phone', city: 'City', agent: 'Agent', follow: 'Next follow-up',
            owner: 'Owner', address: 'Address', site: 'Website', notes: 'Notes', status: 'Status', services: 'Services', subscription: 'Subscription',
            last: 'Last activity', date: 'Date', time: 'Time', type: 'Type', all: 'All', any: 'Any time', today: 'Today', week: 'This week', overdue: 'Overdue',
            overview: 'Overview', features: 'Features', finance: 'Finance', support: 'Support', activity: 'Activity',
            monthly: 'Monthly', balance: 'Outstanding', nextPay: 'Next payment', invoices: 'Invoices', items: 'Billing items',
            paid: 'Paid', unpaid: 'Unpaid', devicesDue: 'Devices left to pay', trial: 'Trial', active: 'Active', paused: 'Paused',
            devices: 'Devices', online: 'Online', offline: 'Offline', heartbeat: 'Last heartbeat', logs: 'Recent logs', tickets: 'Tickets', version: 'Version',
            healthy: 'Healthy', partial: 'Partly offline', critical: 'Needs attention', none: 'No devices',
            enterRestaurant: 'Enter restaurant', adminSettings: 'Restaurant admin settings', oldPos: 'The restaurant is working on the old POS system, no detailed logs yet.', openSite: 'Open site', readFinance: 'From MFITGROUP. Read only.', readSupport: 'From Support. Read only.', locked: 'Not in your access',
            included: 'Included', once: 'Once', each: 'Per message', perMonth: 'per month',
            toastSaved: 'Saved', toastFeature: 'Feature updated', toastDenied: 'You cannot change that', toastConverted: 'Boarding opened. Client details copied.',
            confirmDelete: 'Delete this lead?', confirmConvert: 'Mark it won and open client boarding?',
            yes: 'Confirm', no: 'Back', emptyNotes: 'No notes yet.', emptyAppt: 'No meetings yet.', emptyAct: 'No activity yet.',
            joined: 'Joined the client list', invoice: 'Invoice', ticket: 'Support ticket', featureOn: 'Feature turned on', featureOff: 'Feature turned off', meeting: 'Meeting', converted: 'Converted from a lead',
            board: 'Status', month: 'Month', day: 'Day', prev: 'Previous', next: 'Next', page: 'Page',
            since: 'Since', customer: 'Customer status', assigned: 'Assigned agent', enabled: 'Enabled features',
            businessName: 'Registered business name', taxId: 'Company / dealer no. (ח.פ / ע.מ)', taxIdHint: '9 digits',
            namePh: 'Business name', required: 'Name and phone are required', pick: 'Choose a lead or client',
            noBilling: 'No billing on file yet.', noDevices: 'No devices are connected.', openTicket: 'Open ticket',
            filterAgent: 'Agent', filterCity: 'City', filterStatus: 'Status', filterWhen: 'Follow-up',
            pipeline: 'Sales pipeline', pipelineLead: 'Restaurants, follow-ups and new opportunities',
            leadDetails: 'Lead details', leadHint: 'Edit your opportunity without leaving the board',
            saveChanges: 'Save changes', allAgents: 'All agents', allCities: 'All cities', searchLead: 'Search restaurant or contact',
            weekView: 'Week',
            sun: 'Sun', mon: 'Mon', tue: 'Tue', wed: 'Wed', thu: 'Thu', fri: 'Fri', sat: 'Sat',
            srcAgents: 'Sales Agents', srcFinance: 'Finance', srcSupport: 'Support',
            supTickets: 'Tickets', supDevices: 'Devices', noTickets: 'No tickets for this restaurant.', openTickets: 'open', lastSeen: 'Last seen',
            openFinance: 'Open in Finance',
            saveFailed: 'Could not save. Showing the latest saved data.', finMonthly: 'Monthly payments', finHardware: 'Hardware payments', loadFailed: 'Could not load. Try again.', noFinance: 'Not in MFITGROUP Finance yet.'
        },
        he: {
            leads: 'לידים', calendar: 'לוח זמנים', clients: 'מסעדות', agents: 'סוכני מכירות',
            new: 'ליד חדש', contacted: 'נוצר קשר', meeting: 'פגישה נקבעה', demo: 'הדגמה הושלמה', negotiation: 'משא ומתן', won: 'נסגר', lost: 'אבוד',
            markDone: 'סמן כבוצע', scheduled: 'מתוכנן', confirmed: 'מאושר', done: 'בוצע', missed: 'לא הגיע', cancelled: 'בוטל',
            visit: 'ביקור', call: 'שיחה', followup: 'מעקב', onboarding: 'הקמה',
            searchEmpty: 'אין התאמה.', create: 'יצירה', save: 'שמירה', cancel: 'ביטול', delete: 'מחיקה', edit: 'עריכה',
            convert: 'המרה ללקוח', openClient: 'פתיחת הלקוח', schedule: 'קביעת פגישה', addNote: 'הוספת הערה', notePh: 'כתבו הערה',
            newLead: 'ליד חדש', newAppt: 'פגישה חדשה', newClient: 'לקוח חדש',
            restaurant: 'מסעדה', contact: 'איש קשר', phone: 'טלפון', city: 'עיר', agent: 'סוכן', follow: 'המעקב הבא',
            owner: 'בעלים', address: 'כתובת', site: 'אתר', notes: 'הערות', status: 'סטטוס', services: 'שירותים', subscription: 'מנוי',
            last: 'פעילות אחרונה', date: 'תאריך', time: 'שעה', type: 'סוג', all: 'הכל', any: 'כל הזמנים', today: 'היום', week: 'השבוע', overdue: 'באיחור',
            overview: 'סקירה', features: 'שירותים', finance: 'כספים', support: 'תמיכה', activity: 'פעילות',
            monthly: 'חודשי', balance: 'יתרה', nextPay: 'תשלום הבא', invoices: 'חשבוניות', items: 'פריטי חיוב',
            paid: 'שולם', unpaid: 'לא שולם', devicesDue: 'יתרה לתשלום על ציוד', trial: 'ניסיון', active: 'פעיל', paused: 'מושהה',
            devices: 'מכשירים', online: 'מחובר', offline: 'מנותק', heartbeat: 'פעימה אחרונה', logs: 'לוגים אחרונים', tickets: 'קריאות', version: 'גרסה',
            healthy: 'תקין', partial: 'חלק לא מחובר', critical: 'דורש טיפול', none: 'אין מכשירים',
            enterRestaurant: 'כניסה למסעדה', adminSettings: 'הגדרות ניהול המסעדה', oldPos: 'המסעדה עובדת על מערכת הקופה הישנה, עדיין אין לוגים מפורטים.', openSite: 'פתח אתר', readFinance: 'מ-MFITGROUP. לקריאה בלבד.', readSupport: 'מהתמיכה. לקריאה בלבד.', locked: 'לא בהרשאה שלך',
            included: 'כלול', once: 'חד פעמי', each: 'להודעה', perMonth: 'לחודש',
            toastSaved: 'נשמר', toastFeature: 'השירות עודכן', toastDenied: 'אין הרשאה לשנות את זה', toastConverted: 'הקמת הלקוח נפתחה. פרטי הלקוח הועתקו.',
            confirmDelete: 'למחוק את הליד?', confirmConvert: 'לסמן כנסגר ולפתוח את הקמת הלקוח?',
            yes: 'אישור', no: 'חזרה', emptyNotes: 'אין הערות.', emptyAppt: 'אין פגישות.', emptyAct: 'אין פעילות.',
            joined: 'נכנס לרשימת הלקוחות', invoice: 'חשבונית', ticket: 'קריאת תמיכה', featureOn: 'שירות הופעל', featureOff: 'שירות כובה', meeting: 'פגישה', converted: 'הומר מליד',
            board: 'סטטוס', month: 'חודש', day: 'יום', prev: 'הקודם', next: 'הבא', page: 'עמוד',
            since: 'מאז', customer: 'סטטוס לקוח', assigned: 'סוכן משויך', enabled: 'שירותים פעילים',
            businessName: 'שם העסק הרשום', taxId: 'ח.פ / ע.מ', taxIdHint: '9 ספרות',
            namePh: 'שם העסק', required: 'שם וטלפון חובה', pick: 'בחרו ליד או לקוח',
            noBilling: 'אין חיוב עדיין.', noDevices: 'אין מכשירים מחוברים.', openTicket: 'קריאה פתוחה',
            filterAgent: 'סוכן', filterCity: 'עיר', filterStatus: 'סטטוס', filterWhen: 'מעקב',
            pipeline: 'צינור מכירות', pipelineLead: 'מסעדות, מעקבים והזדמנויות חדשות',
            leadDetails: 'פרטי ליד', leadHint: 'עריכת ההזדמנות בלי לצאת מהלוח',
            saveChanges: 'שמירת שינויים', allAgents: 'כל הסוכנים', allCities: 'כל הערים', searchLead: 'חיפוש מסעדה או איש קשר',
            weekView: 'שבוע',
            sun: 'א׳', mon: 'ב׳', tue: 'ג׳', wed: 'ד׳', thu: 'ה׳', fri: 'ו׳', sat: 'ש׳',
            srcAgents: 'סוכנים', srcFinance: 'כספים', srcSupport: 'תמיכה',
            supTickets: 'קריאות', supDevices: 'מכשירים', noTickets: 'אין קריאות למסעדה הזו.', openTickets: 'פתוחות', lastSeen: 'נראה לאחרונה',
            openFinance: 'פתיחה בכספים',
            saveFailed: 'השמירה נכשלה. מוצגים הנתונים השמורים האחרונים.', finMonthly: 'תשלומים חודשיים', finHardware: 'תשלומי ציוד', loadFailed: 'הטעינה נכשלה. נסו שוב.', noFinance: 'עדיין לא בכספים של MFITGROUP.'
        },
        ar: {
            leads: 'العملاء المحتملون', calendar: 'الجدول', clients: 'المطاعم', agents: 'وكلاء المبيعات',
            new: 'عميل جديد', contacted: 'تم التواصل', meeting: 'اجتماع محدد', demo: 'اكتمل العرض', negotiation: 'تفاوض', won: 'تم الفوز', lost: 'خسارة',
            markDone: 'تحديد كمنجز', scheduled: 'مجدول', confirmed: 'مؤكد', done: 'تم', missed: 'لم يحضر', cancelled: 'أُلغي',
            visit: 'زيارة', call: 'اتصال', followup: 'متابعة', onboarding: 'تشغيل',
            searchEmpty: 'لا توجد نتائج.', create: 'إنشاء', save: 'حفظ', cancel: 'إلغاء', delete: 'حذف', edit: 'تعديل',
            convert: 'تحويل إلى عميل', openClient: 'فتح العميل', schedule: 'جدولة', addNote: 'إضافة ملاحظة', notePh: 'اكتب ملاحظة',
            newLead: 'عميل محتمل', newAppt: 'موعد جديد', newClient: 'عميل جديد',
            restaurant: 'المطعم', contact: 'جهة الاتصال', phone: 'الهاتف', city: 'المدينة', agent: 'الوكيل', follow: 'المتابعة التالية',
            owner: 'المالك', address: 'العنوان', site: 'الموقع', notes: 'ملاحظات', status: 'الحالة', services: 'الخدمات', subscription: 'الاشتراك',
            last: 'آخر نشاط', date: 'التاريخ', time: 'الوقت', type: 'النوع', all: 'الكل', any: 'كل الأوقات', today: 'اليوم', week: 'هذا الأسبوع', overdue: 'متأخر',
            overview: 'نظرة عامة', features: 'الخدمات', finance: 'المالية', support: 'الدعم', activity: 'النشاط',
            monthly: 'شهري', balance: 'المستحق', nextPay: 'الدفعة التالية', invoices: 'الفواتير', items: 'بنود الفوترة',
            paid: 'مدفوع', unpaid: 'غير مدفوع', devicesDue: 'المتبقي على الأجهزة', trial: 'تجريبي', active: 'نشط', paused: 'متوقف',
            devices: 'الأجهزة', online: 'متصل', offline: 'غير متصل', heartbeat: 'آخر نبضة', logs: 'السجلات', tickets: 'التذاكر', version: 'الإصدار',
            healthy: 'سليم', partial: 'انقطاع جزئي', critical: 'يحتاج معالجة', none: 'لا أجهزة',
            enterRestaurant: 'الدخول إلى المطعم', adminSettings: 'إعدادات إدارة المطعم', oldPos: 'المطعم يعمل على نظام نقاط البيع القديم، لا توجد سجلات مفصلة بعد.', openSite: 'فتح الموقع', readFinance: 'من MFITGROUP. للقراءة فقط.', readSupport: 'من الدعم. للقراءة فقط.', locked: 'خارج صلاحيتك',
            included: 'مشمول', once: 'مرة واحدة', each: 'للرسالة', perMonth: '/ شهر',
            toastSaved: 'تم الحفظ', toastFeature: 'تم تحديث الخدمة', toastDenied: 'لا يمكنك تغيير هذا', toastConverted: 'تم فتح تسجيل العميل ونسخ بياناته.',
            confirmDelete: 'حذف هذا العميل المحتمل؟', confirmConvert: 'تعليمه كفوز وفتح تسجيل العميل؟',
            yes: 'تأكيد', no: 'رجوع', emptyNotes: 'لا ملاحظات.', emptyAppt: 'لا اجتماعات.', emptyAct: 'لا نشاط.',
            joined: 'أُضيف إلى العملاء', invoice: 'فاتورة', ticket: 'تذكرة دعم', featureOn: 'تم تفعيل خدمة', featureOff: 'تم إيقاف خدمة', meeting: 'اجتماع', converted: 'حُوّل من عميل محتمل',
            board: 'الحالة', month: 'شهر', day: 'يوم', prev: 'السابق', next: 'التالي', page: 'صفحة',
            since: 'منذ', customer: 'حالة العميل', assigned: 'الوكيل', enabled: 'الخدمات المفعّلة',
            businessName: 'اسم العمل المسجّل', taxId: 'رقم الشركة / المشتغل (ח.פ / ע.מ)', taxIdHint: '9 أرقام',
            namePh: 'اسم العمل', required: 'الاسم والهاتف مطلوبان', pick: 'اختر عميلاً أو مطعماً',
            noBilling: 'لا فوترة بعد.', noDevices: 'لا أجهزة متصلة.', openTicket: 'تذكرة مفتوحة',
            filterAgent: 'الوكيل', filterCity: 'المدينة', filterStatus: 'الحالة', filterWhen: 'المتابعة',
            pipeline: 'مسار المبيعات', pipelineLead: 'مطاعم ومتابعات وفرص جديدة',
            leadDetails: 'تفاصيل العميل المحتمل', leadHint: 'عدّل الفرصة دون مغادرة اللوحة',
            saveChanges: 'حفظ التغييرات', allAgents: 'كل الوكلاء', allCities: 'كل المدن', searchLead: 'ابحث عن مطعم أو جهة اتصال',
            weekView: 'أسبوع',
            sun: 'أحد', mon: 'إثن', tue: 'ثلا', wed: 'أرب', thu: 'خمي', fri: 'جمع', sat: 'سبت',
            srcAgents: 'الوكلاء', srcFinance: 'المالية', srcSupport: 'الدعم',
            supTickets: 'التذاكر', supDevices: 'الأجهزة', noTickets: 'لا توجد تذاكر لهذا المطعم.', openTickets: 'مفتوحة', lastSeen: 'آخر ظهور',
            openFinance: 'فتح في المالية',
            saveFailed: 'تعذّر الحفظ. تظهر آخر البيانات المحفوظة.', finMonthly: 'الدفعات الشهرية', finHardware: 'دفعات الأجهزة', loadFailed: 'تعذّر التحميل. حاول مرة أخرى.', noFinance: 'غير موجود في مالية MFITGROUP بعد.'
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

    // Only a restaurant's real logo is ever shown; without one it gets its initial.
    function logoSrc(entry) {
        return (entry && (entry.logo || (entry.name && entry.name.logo))) || '';
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
        var src = logoSrc(entry);
        var badge = '<b style="background:' + color + '">' + esc(letter) + '</b>';
        if (!src) return '<span class="ag-logo is-letter" aria-hidden="true">' + badge + '</span>';
        // A logo that fails to load falls back to the initial too.
        return '<span class="ag-logo is-logo" aria-hidden="true"><img src="' + esc(src) + '" alt="" onerror="this.parentNode.className=\'ag-logo is-letter\'">' + badge + '</span>';
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

    // The server sends who can be assigned once it has answered. Until then, and on the doors that only
    // borrow the restaurant modal, the signed-in user is the only agent.
    var ME = boot.crm && boot.crm.me ? String(boot.crm.me) : 'me';
    var AGENTS = [{ id: ME, name: boot.user || '—' }];

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


    var STAGES = ['new', 'contacted', 'meeting', 'demo', 'negotiation', 'won', 'lost'];
    var LANE_COLOR = { new: '#5892ee', contacted: '#f58220', meeting: '#e6b349', demo: '#1baa82', negotiation: '#8b6bd7', won: '#128b66', lost: '#d96c3d' };
    var APPT_STATUS = ['scheduled', 'confirmed', 'done', 'missed', 'cancelled'];

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
            var notes = hours;
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

        return {
            version: 3,
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

    // Leads and appointments live on the server. They are only held in memory here, and this page never
    // writes them to local storage.
    var crm = boot.crm || null;
    var crmLoaded = !crm;
    var keepLocal = false;

    function emit() { listeners.forEach(function (fn) { fn(); }); }

    function persist() {
        if (keepLocal) return;
        try { localStorage.setItem(KEY, JSON.stringify(Object.assign({}, state, { leads: [], appointments: [] }))); } catch (e) {}
        if (channel) channel.postMessage(state.seq);
    }

    function readKept() {
        try {
            var kept = JSON.parse(localStorage.getItem(IMPORT_KEY) || 'null');
            if (kept && kept.browser) return kept;
        } catch (e) {}
        return { browser: 'b' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10), leads: [], appointments: [] };
    }

    function mergeKept(list, rows) {
        var ids = rows.map(function (row) { return row.id; });
        return list.filter(function (row) { return ids.indexOf(row.id) === -1; }).concat(rows);
    }

    // What this browser saved before leads moved to the server waits under its own key until it has been
    // handed over, because any door's page can reset the main record before the Agents page is opened.
    function stashLocal(parsed) {
        var leads = Array.isArray(parsed.leads) ? parsed.leads : [];
        var appointments = Array.isArray(parsed.appointments) ? parsed.appointments : [];
        if (!leads.length && !appointments.length) return false;
        var nameOf = function (rows, id) {
            var hit = rows.find(function (row) { return row.id === id; });
            return hit ? text(hit.name) : '';
        };
        var kept = readKept();
        kept.leads = mergeKept(kept.leads, leads.map(function (row) {
            return { id: row.id, name: text(row.name), contact: row.contact, phone: row.phone, city_id: row.cityId, restaurant_id: row.restaurantId, stage: row.stage, next_follow_up_at: row.nextFollowUp, notes: row.notes };
        }));
        kept.appointments = mergeKept(kept.appointments, appointments.map(function (row) {
            return {
                id: row.id,
                lead_id: row.leadId,
                restaurant_id: row.restaurantId,
                restaurant_name: row.restaurantId ? nameOf(parsed.restaurants || [], row.restaurantId) : nameOf(leads, row.leadId),
                at: row.at,
                type: row.type,
                status: row.status,
                notes: row.notes
            };
        }));
        try {
            localStorage.setItem(IMPORT_KEY, JSON.stringify(kept));
        } catch (e) {
            // Nowhere safe to put them yet, so the main record has to keep them.
            keepLocal = true;
            return false;
        }
        return true;
    }

    function load() {
        var stamp = companyStamp(boot.companies);
        var held = state ? { leads: state.leads, appointments: state.appointments } : { leads: [], appointments: [] };
        var parsed = null;
        var rewrite = false;
        try {
            var raw = localStorage.getItem(KEY);
            if (raw) parsed = JSON.parse(raw);
        } catch (e) {}
        if (parsed && parsed.version === 3) rewrite = stashLocal(parsed);
        if (parsed && parsed.version === 3 && Array.isArray(parsed.restaurants) && (!stamp || parsed.companyStamp === stamp)) {
            state = parsed;
        } else {
            state = seed();
            rewrite = true;
        }
        state.leads = held.leads;
        state.appointments = held.appointments;
        if (rewrite) persist();
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
        find: function (id) {
            // The Finance door also opens clients that are not kaman.rest companies; those are never stored.
            return state.restaurants.find(function (row) { return row.id === id; })
                || (boot.extraRestaurants || []).find(function (row) { return row.id === id; });
        },
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
            ['owner', 'phone', 'cityId', 'agentId', 'notes', 'status', 'businessName', 'taxId'].forEach(function (key) {
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
            // Live MFITGROUP Finance data from the server wins over the locally kept demo record.
            var live = boot.finance && boot.finance[id];
            if (live) return live;
            return state.finance[id] || { monthly: 0, nextPayment: null, items: [], invoices: [], plan: 'trial' };
        },
        update: function () { guard('editFinance'); }
    };

    var supportRepository = {
        forRestaurant: function (id) {
            guard('viewSupport');
            // Live tickets and POS devices from the Support door win over the locally kept demo record.
            var live = boot.supportBoot && boot.supportBoot.support && boot.supportBoot.support[id];
            if (live) return live;
            return state.support[id] || { kind: 'none', devices: [], logs: [], tickets: [] };
        },
        update: function () { guard('editSupport'); }
    };

    var tempCount = 0;
    var realIds = {};
    var writes = Promise.resolve();
    var pendingWrites = 0;

    function realId(id) { return realIds[id] || id; }

    function serverId(id) { return id ? (Number(realId(id)) || null) : null; }

    function crmRequest(method, url, body) {
        return fetch(url, {
            method: method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': crm.csrf },
            body: body ? JSON.stringify(body) : undefined
        }).then(function (response) {
            if (!response.ok) throw new Error('crm ' + response.status);
            return response.json();
        });
    }

    function dialogOpen() {
        return !!(ui.leadId || ui.leadDraft || ui.apptId || ui.confirm || ui.restaurantId || ui.clientDraft);
    }

    // A dialog redraws from the state, which would throw away what is being typed in it.
    function settle() {
        var first = !crmLoaded;
        crmLoaded = true;
        if (first || !dialogOpen()) emit();
    }

    function leadFromServer(row) {
        return {
            id: String(row.id),
            name: { en: row.name, he: row.name, ar: row.name },
            contact: row.contact || '',
            phone: row.phone || '',
            cityId: row.city_id || '',
            agentId: row.agent_id ? String(row.agent_id) : '',
            stage: row.stage ? row.stage.value : 'new',
            notes: (row.notes || []).map(function (note) { return { at: note.at, text: note.text }; }),
            restaurantId: row.restaurant_id || null,
            nextFollowUp: row.next_follow_up_at || null
        };
    }

    function appointmentFromServer(row) {
        return {
            id: String(row.id),
            leadId: row.lead_id ? String(row.lead_id) : null,
            restaurantId: row.restaurant_id || null,
            restaurantName: row.restaurant_name || '',
            agentId: row.agent_id ? String(row.agent_id) : '',
            at: row.at,
            type: row.type.value,
            status: row.status.value,
            notes: row.notes || ''
        };
    }

    function setAgents(rows) {
        if (!Array.isArray(rows) || !rows.length) return;
        AGENTS.length = 0;
        rows.forEach(function (row) { AGENTS.push({ id: String(row.id), name: row.name }); });
    }

    // An answer that left before a change was made would wipe that change off the screen, so it is
    // dropped while any change is still on its way.
    function pull() {
        var reads = [];
        if (crm.leads) {
            reads.push(crmRequest('GET', crm.leads).then(function (data) {
                setAgents(data.agents);
                if (pendingWrites) return;
                state.leads = data.leads.map(leadFromServer);
            }));
        }
        if (crm.calendar) {
            reads.push(crmRequest('GET', crm.calendar).then(function (data) {
                setAgents(data.agents);
                if (pendingWrites) return;
                state.appointments = data.appointments.map(appointmentFromServer);
                // The calendar door alone only gets the leads' names, for the meeting's picker.
                if (!crm.leads) state.leads = data.leads.map(leadFromServer);
            }));
        }
        return Promise.all(reads);
    }

    function crmSync() {
        writes = writes.then(pull).then(settle).catch(function () {
            toast(t('loadFailed'));
            settle();
        });
    }

    // Changes show at once and go out one at a time, so an edit made right after a create finds the new id.
    function crmWrite(build) {
        pendingWrites += 1;
        writes = writes.then(function () {
            var call = build();
            return crmRequest(call[0], call[1], call[2]).then(function (data) {
                if (call[3]) call[3](data);
            });
        }).then(function () {
            pendingWrites -= 1;
            if (channel) channel.postMessage('crm');
            settle();
        }, function () {
            pendingWrites -= 1;
            toast(t('saveFailed'));
            return pull().then(emit, emit);
        });
    }

    function clearKept(field) {
        var kept = readKept();
        kept[field] = [];
        try { localStorage.setItem(IMPORT_KEY, JSON.stringify(kept)); } catch (e) {}
    }

    // Once: what this browser held before leads moved to the server. Whatever fails stays for the next
    // page load, and the server takes each row only once however often it is sent.
    function importLocal() {
        var kept = readKept();
        var steps = Promise.resolve();
        if (kept.leads.length && crm.leads) {
            steps = steps.then(function () {
                return crmRequest('POST', crm.leadWrites + '/import', { browser: kept.browser, leads: kept.leads });
            }).then(function () { clearKept('leads'); });
        }
        if (kept.appointments.length && crm.calendar) {
            steps = steps.then(function () {
                return crmRequest('POST', crm.calendarWrites + '/import', { browser: kept.browser, appointments: kept.appointments });
            }).then(function () { clearKept('appointments'); });
        }
        return steps.catch(function () {});
    }

    function leadBody(patch) {
        var body = {};
        ['contact', 'phone', 'stage'].forEach(function (key) {
            if (patch[key] !== undefined) body[key] = patch[key];
        });
        if (patch.name) body.name = patch.name;
        if (patch.cityId !== undefined) body.city_id = patch.cityId || null;
        if (patch.agentId !== undefined) body.agent_id = patch.agentId ? Number(patch.agentId) || null : null;
        if (patch.nextFollowUp !== undefined) body.next_follow_up_at = patch.nextFollowUp;
        if (patch.restaurantId !== undefined) body.restaurant_id = patch.restaurantId;
        return body;
    }

    function saveLead(id, patch) {
        crmWrite(function () {
            return ['PUT', crm.leadWrites + '/' + realId(id), leadBody(patch), function (data) {
                var row = leadRepository.find(id);
                if (row) Object.assign(row, leadFromServer(data.lead));
            }];
        });
    }

    var leadRepository = {
        all: function () { return state.leads.slice(); },
        find: function (id) {
            var wanted = realId(id);
            return state.leads.find(function (row) { return row.id === wanted; });
        },
        create: function (input) {
            guard('manageCrm');
            tempCount += 1;
            var id = 'new-' + tempCount;
            var body = leadBody(input);
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
            emit();
            crmWrite(function () {
                return ['POST', crm.leadWrites, body, function (data) {
                    var saved = leadFromServer(data.lead);
                    var row = state.leads.find(function (item) { return item.id === id; });
                    realIds[id] = saved.id;
                    if (row) Object.assign(row, saved);
                    if (ui.leadId === id) ui.leadId = saved.id;
                    state.appointments.forEach(function (item) {
                        if (item.leadId === id) item.leadId = saved.id;
                    });
                }];
            });
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
            emit();
            saveLead(id, patch);
        },
        move: function (id, stage) {
            guard('manageCrm');
            var row = leadRepository.find(id);
            if (!row || STAGES.indexOf(stage) === -1) return;
            row.stage = stage;
            emit();
            saveLead(id, { stage: stage });
        },
        note: function (id, text) {
            guard('manageCrm');
            var row = leadRepository.find(id);
            if (!row || !text) return;
            row.notes.unshift({ at: new Date().toISOString(), text: text });
            if (row.restaurantId) {
                touch(row.restaurantId, { source: 'agents', key: 'meeting', vars: { name: text } });
                persist();
            }
            emit();
            crmWrite(function () {
                return ['POST', crm.leadWrites + '/' + realId(id) + '/notes', { text: text }, function (data) {
                    var saved = leadRepository.find(id);
                    if (saved) Object.assign(saved, leadFromServer(data.lead));
                }];
            });
        },
        remove: function (id) {
            guard('manageCrm');
            var wanted = realId(id);
            state.leads = state.leads.filter(function (row) { return row.id !== wanted; });
            state.appointments = state.appointments.filter(function (row) { return row.leadId !== wanted; });
            emit();
            crmWrite(function () { return ['DELETE', crm.leadWrites + '/' + realId(id)]; });
        },
        convert: function (id) {
            guard('manageCrm');
            var row = leadRepository.find(id);
            if (!row) return null;
            row.stage = 'won';
            if (row.restaurantId && restaurantRepository.find(row.restaurantId)) {
                emit();
                saveLead(id, { stage: 'won' });
                return row.restaurantId;
            }
            // The client itself is created by the boarding site, and reaches the list from kaman.rest.
            emit();
            saveLead(id, { stage: 'won' });
            return null;
        }
    };

    // Client boarding, with the lead's details in the link for the site to fill its first step.
    // A browser cannot fill another site's form, so they are also copied, ready to paste.
    function openBoarding(lead) {
        var contact = String(lead.contact || '').trim();
        var space = contact.search(/\s/);
        var query = [
            ['firstName', space < 0 ? contact : contact.slice(0, space)],
            ['lastName', space < 0 ? '' : contact.slice(space).trim()],
            ['phone', String(lead.phone || '').trim()],
            ['restaurant', text(lead.name)]
        ].filter(function (pair) { return pair[1]; }).map(function (pair) {
            return pair[0] + '=' + encodeURIComponent(pair[1]);
        }).join('&');
        var lang = ['he', 'ar', 'en'].indexOf(loc) >= 0 ? loc : 'he';
        window.open('https://boarding.mfitgroup.com/' + lang + (query ? '?' + query : ''), '_blank', 'noopener');
        if (navigator.clipboard) {
            navigator.clipboard.writeText([contact, lead.phone, text(lead.name)].filter(Boolean).join('\n')).catch(function () {});
        }
    }

    function appointmentBody(patch) {
        var body = {};
        ['at', 'type', 'status', 'notes'].forEach(function (key) {
            if (patch[key] !== undefined) body[key] = patch[key];
        });
        if (patch.agentId !== undefined) body.agent_id = patch.agentId ? Number(patch.agentId) || null : null;
        if (patch.leadId !== undefined) body.lead_id = serverId(patch.leadId);
        if (patch.restaurantId !== undefined) {
            var restaurant = patch.restaurantId ? restaurantRepository.find(patch.restaurantId) : null;
            body.restaurant_id = patch.restaurantId;
            body.restaurant_name = restaurant ? text(restaurant.name) : null;
        }
        return body;
    }

    var calendarRepository = {
        all: function () { return state.appointments.slice(); },
        find: function (id) {
            var wanted = realId(id);
            return state.appointments.find(function (row) { return row.id === wanted; });
        },
        create: function (input) {
            guard('manageCrm');
            tempCount += 1;
            var id = 'new-' + tempCount;
            var row = {
                id: id,
                leadId: input.leadId || null,
                restaurantId: input.restaurantId || null,
                restaurantName: '',
                agentId: input.agentId,
                at: input.at,
                type: input.type || 'visit',
                status: input.status || 'scheduled',
                notes: input.notes || ''
            };
            state.appointments.unshift(row);
            var restaurantId = input.restaurantId;
            if (!restaurantId && input.leadId) {
                var lead = leadRepository.find(input.leadId);
                if (lead) {
                    lead.nextFollowUp = input.at;
                    restaurantId = lead.restaurantId;
                }
            }
            if (restaurantId) {
                touch(restaurantId, { source: 'agents', key: 'meeting' });
                persist();
            }
            emit();
            crmWrite(function () {
                return ['POST', crm.calendarWrites, appointmentBody(input), function (data) {
                    var saved = appointmentFromServer(data.appointment);
                    realIds[id] = saved.id;
                    Object.assign(row, saved);
                    if (ui.apptId === id) ui.apptId = saved.id;
                }];
            });
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
                if (lead && patch.at !== undefined) lead.nextFollowUp = row.at;
            }
            if (restaurantId) {
                touch(restaurantId, { source: 'agents', key: 'meeting' });
                persist();
            }
            emit();
            crmWrite(function () {
                return ['PUT', crm.calendarWrites + '/' + realId(id), appointmentBody(patch), function (data) {
                    var saved = calendarRepository.find(id);
                    if (saved) Object.assign(saved, appointmentFromServer(data.appointment));
                }];
            });
        }
    };

    load();
    if (channel) {
        channel.onmessage = function (event) {
            if (event.data === 'crm') {
                if (crm) crmSync();
                return;
            }
            load();
            emit();
        };
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
        finTab: 'monthly',
        supTab: 'devices',
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
    function agentById(id) {
        // Restaurants this browser created before agents came from the server name their agent 'me'.
        var wanted = id === 'me' ? ME : id;
        return AGENTS.find(function (agent) { return agent.id === wanted; });
    }

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
            return restaurant ? text(restaurant.name) : (row.restaurantName || row.restaurantId);
        }
        var lead = leadRepository.find(row.leadId);
        return lead ? text(lead.name) : (row.restaurantName || '');
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
                    (logoSrc(lead.name) ? '<img class="sales-photo" src="' + esc(logoSrc(lead.name)) + '" alt="">' : '') +
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
            '<select data-ds-select data-filter="agent" aria-label="' + esc(t('filterAgent')) + '"><option value="">' + esc(t('allAgents')) + '</option>' + selectAgents(ui.filters.agent) + '</select>' +
            '<select data-ds-select data-filter="city" aria-label="' + esc(t('filterCity')) + '"><option value="">' + esc(t('allCities')) + '</option>' + selectCities(ui.filters.city) + '</select>' +
            '<select data-ds-select data-filter="stage" aria-label="' + esc(t('filterStatus')) + '"><option value="">' + esc(t('filterStatus')) + '</option>' + options(STAGES, ui.filters.stage) + '</select>' +
            '<select data-ds-select data-filter="when" aria-label="' + esc(t('filterWhen')) + '"><option value="">' + esc(t('any')) + '</option><option value="today"' + (ui.filters.when === 'today' ? ' selected' : '') + '>' + esc(t('today')) + '</option><option value="week"' + (ui.filters.when === 'week' ? ' selected' : '') + '>' + esc(t('week')) + '</option><option value="overdue"' + (ui.filters.when === 'overdue' ? ' selected' : '') + '>' + esc(t('overdue')) + '</option></select></div>' +
            '<div class="sales-board">' + columns + '</div>';
    }

    function toolbar(act, label) {
        return '<div class="ag-toolbar">' +
            '<button type="button" class="ag-btn" data-act="' + act + '">' + esc(label) + '</button>' +
            '<select data-ds-select data-filter="agent" aria-label="' + esc(t('filterAgent')) + '"><option value="">' + esc(t('all')) + '</option>' + selectAgents(ui.filters.agent) + '</select>' +
            '<select data-ds-select data-filter="city" aria-label="' + esc(t('filterCity')) + '"><option value="">' + esc(t('all')) + '</option>' + selectCities(ui.filters.city) + '</select>' +
            (ui.page === 'leads'
                ? '<select data-ds-select data-filter="stage" aria-label="' + esc(t('filterStatus')) + '"><option value="">' + esc(t('all')) + '</option>' + options(STAGES, ui.filters.stage) + '</select>' +
                  '<select data-ds-select data-filter="when" aria-label="' + esc(t('filterWhen')) + '"><option value="">' + esc(t('any')) + '</option><option value="today"' + (ui.filters.when === 'today' ? ' selected' : '') + '>' + esc(t('today')) + '</option><option value="week"' + (ui.filters.when === 'week' ? ' selected' : '') + '>' + esc(t('week')) + '</option><option value="overdue"' + (ui.filters.when === 'overdue' ? ' selected' : '') + '>' + esc(t('overdue')) + '</option></select>'
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
        var agentFilter = '<select data-ds-select data-cal-agent aria-label="' + esc(t('agent')) + '"><option value="">' + esc(t('all')) + '</option>' + selectAgents(ui.calAgent) + '</select>';
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
            '<select data-ds-select data-filter="agent"><option value="">' + esc(t('all')) + '</option>' + selectAgents(ui.filters.agent) + '</select>' +
            '<select data-ds-select data-filter="city"><option value="">' + esc(t('all')) + '</option>' + selectCities(ui.filters.city) + '</select>' +
            '<select data-ds-select data-filter="stage"><option value="">' + esc(t('all')) + '</option>' + options(['active', 'onboarding', 'paused'], ui.filters.stage) + '</select>' +
            '<select data-ds-select data-filter="billing"><option value="">' + esc(t('subscription')) + '</option>' + options(['paid', 'unpaid', 'overdue', 'trial'], ui.filters.billing) + '</select></div>';
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
            '<select data-ds-select data-filter="city" aria-label="' + esc(t('filterCity')) + '"><option value="">' + esc(t('allCities')) + '</option>' + selectCities(ui.filters.city) + '</select>' +
            '<select data-ds-select data-filter="stage" aria-label="' + esc(t('filterStatus')) + '"><option value="">' + esc(copy('allStatuses') || t('all')) + '</option>' + options(['active', 'onboarding', 'paused'], ui.filters.stage) + '</select></div>' +
            (rows.length ? '<div class="rest-grid">' + rows.map(restaurantRow).join('') + '</div>' : '<p class="overview-empty">' + esc(t('searchEmpty')) + '</p>');
    }

    function renderView() {
        // The Finance door only borrows the restaurant modal; its page is rendered on the server.
        if (!view) return;
        if (ui.booting || !crmLoaded) {
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
            '<label class="ag-field">' + esc(t('city')) + '<select data-ds-select name="cityId">' + selectCities(values.cityId) + '</select></label>' +
            '<label class="ag-field">' + esc(t('agent')) + '<select data-ds-select name="agentId">' + selectAgents(values.agentId) + '</select></label>';
    }

    function renderLead() {
        if (!ui.leadId && !ui.leadDraft) return '';
        var lead = ui.leadId ? leadRepository.find(ui.leadId) : null;
        if (ui.leadId && !lead) { ui.leadId = null; return ''; }
        var values = lead ? { name: text(lead.name), contact: lead.contact, phone: lead.phone, cityId: lead.cityId, agentId: lead.agentId, stage: lead.stage, next: lead.nextFollowUp ? toInput(lead.nextFollowUp) : '' } : { agentId: ME, cityId: CITIES[0].id, stage: 'new', next: '' };
        var notes = lead ? lead.notes.map(function (note) {
            return '<div class="ag-note"><strong>' + esc(formatDay(note.at)) + '</strong><div>' + esc(note.text) + '</div></div>';
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
            '<label>' + esc(t('city')) + '<select data-ds-select name="cityId">' + selectCities(values.cityId) + '</select></label>' +
            '<label>' + esc(t('agent')) + '<select data-ds-select name="agentId">' + selectAgents(values.agentId) + '</select></label>' +
            '<label>' + esc(t('status')) + '<select data-ds-select name="stage">' + options(STAGES, values.stage) + '</select></label></form>' +
            (lead ? '<div class="sales-notes"><h3>' + esc(t('notes')) + '</h3>' + (notes ? notes.replace(/ag-note/g, 'sales-note') : '<p class="sales-empty-note">' + esc(t('emptyNotes')) + '</p>') +
                '<form data-form="note" data-id="' + lead.id + '"><textarea name="text" placeholder="' + esc(t('notePh')) + '"></textarea><button class="sales-btn" type="submit">' + esc(t('addNote')) + '</button></form></div>' : '') +
            '</div><div class="sales-modal-foot">' +
            (lead ? '<button type="button" class="sales-btn sales-delete" data-act="ask-delete" data-id="' + lead.id + '">' + esc(t('delete')) + '</button>' : '<span></span>') +
            '<div>' +
            (lead && lead.restaurantId && restaurantRepository.find(lead.restaurantId) ? '<button type="button" class="sales-btn" data-act="open-client" data-id="' + lead.restaurantId + '">' + esc(t('openClient')) + '</button>' : '') +
            (lead && !(lead.restaurantId && restaurantRepository.find(lead.restaurantId)) ? '<button type="button" class="sales-btn" data-act="ask-convert" data-id="' + lead.id + '">' + esc(t('convert')) + '</button>' : '') +
            '<button type="submit" class="sales-btn sales-btn--primary" form="ag-lead-form">' + esc(t('saveChanges')) + '</button></div></div></div>';
    }

    function renderRestaurant() {
        if (!ui.restaurantId) return '';
        var row = restaurantRepository.find(ui.restaurantId);
        if (!row) { ui.restaurantId = null; return ''; }
        var lockedTabs = { finance: !!(boot.financeLinks && boot.financeLinks.locked), support: !!SUP.locked };
        (SUP.lockedTabs || []).forEach(function (tab) { lockedTabs[tab] = true; });
        var detailsLocked = !!lockedTabs.overview;
        var tabs = ['overview', 'features', 'finance', 'support', 'activity'].map(function (tab) {
            var mark = lockedTabs[tab] ? '<svg class="rm-tab-lock" viewBox="0 0 24 24" role="img" aria-label="' + esc(t('locked')) + '"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>' : '';
            return '<button type="button" role="tab" aria-selected="' + (ui.tab === tab ? 'true' : 'false') + '" data-act="tab" data-tab="' + tab + '" class="' + (ui.tab === tab ? 'is-on' : '') + (lockedTabs[tab] ? ' is-locked' : '') + '">' + esc(t(tab)) + mark + '</button>';
        }).join('');
        var body = '';
        if (ui.tab !== 'finance' && ui.tab !== 'support' && lockedTabs[ui.tab]) {
            body = rmLocked(supLabel('locked'));
        } else if (ui.tab === 'overview') {
            if (ui.editing) {
                // Same order as the read view, paired so no field sits alone; the name spans the row.
                var field = function (label, control, wide) {
                    return '<label class="ag-field' + (wide ? ' rm-form__wide' : '') + '"><span>' + esc(label) + '</span>' + control + '</label>';
                };
                body = '<form class="rm-form" data-form="client"><div class="rm-form__grid">' +
                    field(t('restaurant'), '<input name="name" required value="' + esc(text(row.name)) + '" placeholder="' + esc(t('namePh')) + '">', true) +
                    field(t('customer'), '<select data-ds-select name="status">' + options(['active', 'onboarding', 'paused'], row.status) + '</select>') +
                    field(t('agent'), '<select data-ds-select name="agentId">' + selectAgents(row.agentId) + '</select>') +
                    field(t('businessName'), '<input name="businessName" value="' + esc(row.businessName || '') + '">') +
                    field(t('taxId'), '<input name="taxId" dir="ltr" inputmode="numeric" pattern="[0-9]{8,9}" maxlength="9" placeholder="' + esc(t('taxIdHint')) + '" value="' + esc(row.taxId || '') + '">') +
                    field(t('contact'), '<input name="contact" value="' + esc(row.owner || '') + '">') +
                    field(t('phone'), '<input name="phone" type="tel" dir="ltr" value="' + esc(row.phone || '') + '">') +
                    field(t('city'), '<select data-ds-select name="cityId">' + selectCities(row.cityId) + '</select>') +
                    field(t('address'), '<input name="address" value="' + esc(text(row.address)) + '">') +
                    field(t('notes'), '<textarea name="notes" rows="3">' + esc(row.notes || '') + '</textarea>', true) +
                    '</div><div class="rm-form__foot"><button type="button" class="rm-btn rm-btn--ghost" data-act="cancel-edit">' + esc(t('cancel')) + '</button>' +
                    '<button type="submit" class="rm-btn rm-btn--primary">' + esc(t('save')) + '</button></div></form>';
            } else {
                body = '<div class="rm-info">' +
                    kv(t('businessName'), row.businessName || '') + kv(t('taxId'), row.taxId || '') +
                    kv(t('owner'), row.owner) + kv(t('phone'), row.phone) + kv(t('city'), text(cityById(row.cityId))) + kv(t('address'), text(row.address)) +
                    kv(t('assigned'), (agentById(row.agentId) || {}).name || '') + kv(t('customer'), t(row.status)) +
                    kv(t('since'), formatDay(row.onboardedAt)) + kv(t('enabled'), String(enabledCount(row.id))) +
                    '</div><div class="rm-note"><span>' + esc(t('notes')) + '</span><p>' + esc(cleanNotes(row) || t('emptyNotes')) + '</p></div>' +
                    '<div class="rm-actions"><button type="button" class="rm-btn rm-btn--ghost" data-act="edit-client">' + rmIcon('edit') + '<span>' + esc(t('edit')) + '</span></button></div>';
            }
        } else if (ui.tab === 'features') {
            var map = featuresRepository.forRestaurant(row.id);
            body = '<div class="ag-features">' + CATALOG.map(function (feature) {
                var on = !!map[feature.id];
                return '<article class="ag-feature"><div><strong>' + esc(text(feature.name)) + '</strong><p>' + esc(text(feature.desc)) + '</p><em>' + esc(priceLabel(feature)) + (feature.locked ? ' · ' + t('locked') : '') + '</em></div>' +
                    '<button type="button" class="ag-switch' + (on ? ' is-on' : '') + '" data-act="toggle-feature" data-id="' + row.id + '" data-feature="' + feature.id + '" data-on="' + (on ? '1' : '0') + '" aria-pressed="' + (on ? 'true' : 'false') + '"' + (feature.locked ? ' disabled' : '') + '><i></i></button></article>';
            }).join('') + '</div>';
        } else if (ui.tab === 'finance') {
            body = renderFinance(row);
        } else if (ui.tab === 'support') {
            body = renderSupport(row);
        } else {
            var events = state.activity.filter(function (item) { return item.restaurantId === row.id; }).sort(function (a, b) { return new Date(b.at) - new Date(a.at); });
            body = '<div class="ag-timeline">' + (events.map(function (item) {
                return '<article><time>' + esc(formatDay(item.at)) + '</time><div><span class="ag-src">' + esc(sourceLabel(item.source)) + '</span><div>' + esc(activityText(item)) + '</div></div></article>';
            }).join('') || '<p class="ag-sub">' + esc(t('emptyAct')) + '</p>') + '</div>';
        }
        var brand = /^#[0-9a-f]{3,8}$/i.test((row.name && row.name.color) || '') ? row.name.color : '#f58220';
        var art = logoSrc(row.name);
        var loginAs = detailsLocked ? '' : loginAsUrl(row);
        var adminEdit = detailsLocked ? '' : adminSettingsUrl(row);
        return '<div class="ag-modal rm" data-dialog role="document" style="--rm-brand:' + brand + '">' +
            '<div class="rm-hero' + (art ? ' is-logo' : ' is-plain') + '">' +
            (art ? '<img class="rm-hero__blur" src="' + esc(art) + '" alt=""><img class="rm-hero__art" src="' + esc(art) + '" alt="" onerror="this.parentNode.className=\'rm-hero is-plain\'">' : '') +
            '<button type="button" class="rm-x" data-act="close" aria-label="' + esc(t('cancel')) + '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>' +
            '<header class="rm-head">' + logoHtml(row.name) +
            '<div class="rm-title"><div class="rm-title__row"><h2 id="ag-portal-title">' + esc(text(row.name)) + '</h2>' + (detailsLocked ? '' : '<span class="ag-pill ' + esc(row.status) + '">' + esc(t(row.status)) + '</span>') + '</div>' +
            (detailsLocked ? '' : '<div class="rm-meta"><span>' + enabledCount(row.id) + ' ' + esc(t('services')) + '</span></div>') + '</div>' +
            '<div class="rm-cta">' +
            (adminEdit ? '<a class="rm-btn rm-btn--ghost" href="' + esc(adminEdit) + '" target="_blank" rel="noopener">' + rmIcon('gear') + '<span>' + esc(t('adminSettings')) + '</span></a>' : '') +
            (loginAs ? '<a class="rm-btn rm-btn--primary" href="' + esc(loginAs) + '">' + rmIcon('enter') + '<span>' + esc(t('enterRestaurant')) + '</span></a>' : '') +
            '</div></header>' +
            '<nav class="rm-tabs" role="tablist">' + tabs + '</nav><div class="ag-modal-body rm-body">' + body + '</div></div>';
    }

    // Finance has two sections: monthly payments and hardware payments. In the Finance door they are the
    // working Finance panels, loaded from the server; elsewhere they are a read-only summary.
    function rmLocked(label) {
        return '<div class="rm-locked">' +
            '<svg class="door-lock__svg" viewBox="0 0 64 64" aria-hidden="true"><path class="door-lock__shackle" d="M20 30V20a12 12 0 0 1 24 0v10"/><rect class="door-lock__body" x="10" y="27" width="44" height="33" rx="9" style="fill:var(--lock-steel)"/><path class="door-lock__hole" d="M32 37a4.6 4.6 0 0 0-2.3 8.6V50a2.3 2.3 0 0 0 4.6 0v-4.4A4.6 4.6 0 0 0 32 37Z"/></svg>' +
            '<span>' + esc(label) + '</span></div>';
    }

    function renderFinance(row) {
        if (boot.financeLinks && boot.financeLinks.locked) return rmLocked(boot.financeLinks.lockedLabel);
        var fin = financeRepository.forRestaurant(row.id);
        var sub = ui.finTab === 'hardware' ? 'hardware' : 'monthly';
        var bar = '<div class="rm-subtabs" role="tablist">' + ['monthly', 'hardware'].map(function (key) {
            return '<button type="button" role="tab" data-act="fin-tab" data-tab="' + key + '" aria-selected="' + (sub === key ? 'true' : 'false') + '" class="' + (sub === key ? 'is-on' : '') + '">' + esc(t(key === 'monthly' ? 'finMonthly' : 'finHardware')) + '</button>';
        }).join('') + '</div>';
        if (boot.financeEmbed) {
            if (!fin.clientId) return bar + rmEmpty('wallet', t('noFinance'));
            return bar + '<div class="rm-embed" data-fin-embed="' + esc(embedUrl(fin.clientId, sub)) + '"></div>';
        }
        // Other doors: the same Finance sections, read-only; changes are made in the Finance door.
        var links = boot.financeLinks || {};
        if (links.preview && fin.clientId) {
            var open = links.open ? '<a class="rm-btn rm-btn--ghost rm-btn--sm" href="' + esc(String(links.open).replace('__ID__', encodeURIComponent(fin.clientId)) + '&tab=' + (sub === 'hardware' ? 'invoices' : 'payments')) + '">' + rmIcon('enter') + '<span>' + esc(t('openFinance')) + '</span></a>' : '';
            var url = String(links.preview).replace('__ID__', encodeURIComponent(fin.clientId)) + '?tab=' + (sub === 'hardware' ? 'invoices' : 'payments');
            return '<div class="rm-subbar">' + bar + open + '</div><div class="rm-embed" data-fin-embed="' + esc(url) + '"></div>';
        }
        if (sub === 'monthly') {
            var items = fin.items || [];
            return bar + '<div class="rm-fin rm-fin--two">' +
                '<article class="rm-fin__main"><span>' + rmIcon('wallet') + esc(t('monthly')) + '</span><strong>' + esc(formatMoney(fin.monthly)) + '</strong><small>' + esc(t('readFinance')) + '</small></article>' +
                '<article class="rm-fin__stat"><span>' + rmIcon('calendar') + esc(t('nextPay')) + '</span><strong>' + esc(fin.nextPayment ? formatDay(fin.nextPayment) : '—') + '</strong></article>' +
                '</div>' +
                '<section class="rm-card"><header><h3>' + esc(t('items')) + '</h3><b>' + items.length + '</b></header>' +
                (items.map(function (item) {
                    var feature = CATALOG.find(function (f) { return f.id === item.featureId; });
                    var itemName = item.name || (feature ? text(feature.name) : item.featureId);
                    return '<div class="rm-row"><span class="rm-row__name">' + esc(itemName) + '</span><span class="rm-row__meta">' + esc(t(item.name || item.priceType === 'monthly' ? 'perMonth' : item.priceType === 'once' ? 'once' : 'each')) + '</span><strong>' + esc(formatMoney(item.amount)) + '</strong></div>';
                }).join('') || rmEmpty('receipt', t('noBilling'))) + '</section>';
        }
        var invoices = fin.invoices || [];
        var unpaid = invoices.filter(function (inv) { return inv.status !== 'paid'; });
        var hasDevicesDue = typeof fin.devicesDue === 'number';
        var outstanding = hasDevicesDue ? fin.devicesDue : unpaid.reduce(function (sum, inv) { return sum + inv.amount; }, 0);
        return bar + '<div class="rm-fin rm-fin--one">' +
            '<article class="rm-fin__stat' + (outstanding > 0 ? ' is-due' : ' is-clear') + '"><span>' + rmIcon('alert') + esc(t('devicesDue')) + '</span><strong>' + esc(formatMoney(outstanding)) + '</strong><small>' + esc(t('readFinance')) + '</small></article>' +
            '</div>' +
            '<section class="rm-card"><header><h3>' + esc(t('invoices')) + '</h3><b>' + invoices.length + '</b></header>' +
            (invoices.map(function (inv) {
                return '<div class="rm-row"><span class="rm-row__name"><bdi dir="ltr">' + esc(inv.number) + '</bdi></span><span class="rm-row__meta">' + esc(formatDay(inv.issued)) + '</span><strong>' + esc(formatMoney(inv.amount)) + '</strong><span class="ag-pill ' + inv.status + '">' + esc(t(inv.status)) + '</span></div>';
            }).join('') || rmEmpty('file', t('noBilling'))) + '</section>';
    }

    // Support is the Support door's own restaurant view: overview, devices, recent logs and tickets.
    // People who can open the Support door get it live from there (the board's card and log feed);
    // everyone else gets the same sections, read-only, from what the page was given.
    var SUP = boot.supportBoot || {};
    var supCards = {};
    var supLogs = {};

    function supLabel(key) { return (SUP.labels && SUP.labels[key]) || t(key); }

    // The page's own data shaped like a board card, for when the live card is not available.
    function supBasicCard(sup) {
        var devices = (sup.devices || []).map(function (device) {
            return {
                name: device.cashier || device.name, kind: device.kind, platform: '', version: device.version, product: device.product,
                deviceId: device.name, online: !!device.online, old: !!device.old, status: device.online ? 'online' : 'offline',
                lastSeenAt: device.lastSeen, lastSeen: device.lastSeen ? formatDay(device.lastSeen) + ' ' + formatTime(device.lastSeen) : '', href: device.url
            };
        });
        var last = devices.map(function (device) { return device.lastSeenAt || ''; }).sort().pop();
        return {
            id: sup.slug || '', online: devices.filter(function (device) { return device.online; }).length, total: devices.length,
            lastSeen: last ? formatDay(last) + ' ' + formatTime(last) : '', error: null, products: '', devices: devices,
            tickets: (sup.tickets || []).map(function (ticket) {
                return { id: ticket.id, summary: ticket.title, status: ticket.status, label: ticket.statusLabel, href: ticket.url, at: ticket.at ? formatDay(ticket.at) : '' };
            })
        };
    }

    function supCard(sup) {
        var slug = sup.slug || '';
        if (!SUP.card || !slug) return { card: supBasicCard(sup), live: false };
        var entry = supCards[slug];
        if (entry && entry.card) return { card: entry.card, live: true, logs: entry.logs };
        if (entry && entry.failed) return { card: supBasicCard(sup), live: false };
        if (!entry) {
            supCards[slug] = { loading: true };
            fetch(String(SUP.card).replace('__SLUG__', encodeURIComponent(slug)), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (res) { if (!res.ok) throw new Error(String(res.status)); return res.json(); })
                .then(function (payload) { supCards[slug] = { card: payload.card, logs: !!payload.logs }; })
                .catch(function () { supCards[slug] = { failed: true }; })
                .then(function () { if (ui.tab === 'support') renderLayer(); });
        }
        return null;
    }

    function supFeed(slug, query) {
        var key = slug + '|' + query;
        var entry = supLogs[key];
        if (entry) return entry.logs || null;
        supLogs[key] = { loading: true };
        fetch(SUP.feed + '?restaurant=' + encodeURIComponent(slug) + '&' + query, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (payload) { supLogs[key] = { logs: (payload && payload.logs) || [] }; })
            .catch(function () { supLogs[key] = { logs: [] }; })
            .then(function () { if (ui.tab === 'support') renderLayer(); });
        return null;
    }

    function supHttp(log) {
        var ctx = log.context || {};
        var code = ctx.status != null && ctx.status !== '' ? String(ctx.status) : '';
        var method = typeof ctx.method === 'string' ? ctx.method.toUpperCase() : '';
        var endpoint = typeof ctx.path === 'string' ? ctx.path : typeof ctx.url === 'string' ? ctx.url : typeof ctx.endpoint === 'string' ? ctx.endpoint : '';
        var message = typeof ctx.error === 'string' ? ctx.error : (ctx.error && typeof ctx.error.message === 'string' ? ctx.error.message : '');
        var raw = String(log.message || '');
        var lead = raw.match(/^(GET|POST|PUT|PATCH|DELETE|HEAD)\s+(\S+)/i);
        if (lead) {
            if (!method) method = lead[1].toUpperCase();
            if (!endpoint) endpoint = lead[2];
        }
        var match = raw.match(/->\s*(\d{3})\s*([\s\S]*)$/);
        if (!code && match) code = match[1];
        if (!message && match) message = match[2];
        if (!message && !lead) message = raw;
        message = String(message || '').replace(/^\d{3}\s*/, '').replace(/\s+/g, ' ').trim();
        if (message.length > 140) message = message.slice(0, 137) + '…';
        return { code: code, message: message, endpoint: [method, endpoint].filter(Boolean).join(' ') };
    }

    function supLogRow(log) {
        var http = supHttp(log);
        var at = String(log.at || '').match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/);
        return '<div class="rm-log is-' + esc(log.level) + '"><i></i><div><div class="rm-log__fact" dir="ltr">' +
            (at ? '<time>' + esc(at[2] + ' · ' + at[1]) + '</time>' : '') +
            (http.code ? '<b>' + esc(http.code) + '</b>' : '') +
            (http.endpoint ? '<span title="' + esc(http.endpoint) + '">' + esc(http.endpoint) + '</span>' : '') + '</div>' +
            (http.message && http.message !== http.endpoint ? '<strong dir="auto">' + esc(http.message) + '</strong>' : '') + '</div></div>';
    }

    function supBadge(status) {
        var live = status === 'online' || status === 'healthy' || status === 'warning';
        return '<span class="rm-status rm-status--' + esc(status || 'unknown') + '">' + (live ? '<i class="rm-live" aria-hidden="true"></i>' : '') + esc(supLabel(status || 'unknown')) + '</span>';
    }

    function supDeviceRow(device, extra, slug) {
        var product = device.product ? supLabel(device.product) : '';
        var meta = [device.kind, device.platform, product].filter(Boolean).map(esc).join(' · ') +
            (device.version ? ' · <bdi dir="ltr">' + esc(device.version) + '</bdi>' : '');
        var inner = '<div class="rm-dev__top"><div class="rm-dev__name"><strong>' + esc(device.name || '—') + '</strong><small>' + meta +
            '<br>' + esc(device.deviceId || '') + ' · ' + esc(device.lastSeen || supLabel('never')) + '</small></div>' + supBadge(device.status) + '</div>' + (extra || '');
        // On the Support board a device opens its printer tools, as the board always did.
        if (window.SupportBoard && slug && device.id != null) {
            return '<button type="button" class="rm-dev" data-act="sup-printers" data-slug="' + esc(slug) + '" data-device="' + esc(device.id) + '">' + inner + '</button>';
        }
        return device.href ? '<a class="rm-dev" href="' + esc(device.href) + '">' + inner + '</a>' : '<div class="rm-dev">' + inner + '</div>';
    }

    function renderSupport(row) {
        if (SUP.locked) return rmLocked(supLabel('locked'));
        var sup = supportRepository.forRestaurant(row.id);
        var got = supCard(sup);
        var withLogs = !!(got && got.live && got.logs && SUP.feed);
        var keys = withLogs ? ['devices', 'logs', 'tickets'] : ['devices', 'tickets'];
        var sub = keys.indexOf(ui.supTab) >= 0 ? ui.supTab : 'devices';
        var names = { devices: 'devices', logs: 'recent', tickets: 'tickets' };
        var bar = '<div class="rm-subbar"><div class="rm-subtabs" role="tablist">' + keys.map(function (key) {
            return '<button type="button" role="tab" data-act="sup-tab" data-tab="' + key + '" aria-selected="' + (sub === key ? 'true' : 'false') + '" class="' + (sub === key ? 'is-on' : '') + '">' + esc(supLabel(names[key])) + '</button>';
        }).join('') + '</div>' +
            (SUP.board && sup.slug && !window.SupportBoard ? '<a class="rm-btn rm-btn--ghost rm-btn--sm" href="' + esc(SUP.board + '?open=' + encodeURIComponent(sup.slug)) + '">' + rmIcon('enter') + '<span>' + esc(supLabel('openBoard')) + '</span></a>' : '') + '</div>';
        if (!got) return bar + '<div class="ag-skel"><i></i><i></i><i></i></div>';
        var card = got.card;
        var devices = card.devices || [];
        if (sub === 'tickets') {
            var tickets = card.tickets || [];
            return bar + (tickets.length ? '<section class="rm-card">' + tickets.map(function (ticket) {
                var inner = '<span class="rm-row__name"><bdi dir="ltr">' + esc(ticket.id || '') + '</bdi> · ' + esc(ticket.summary || '') + '</span>' +
                    '<span class="rm-row__meta">' + esc(ticket.at || '') + '</span>' +
                    '<span class="rm-status rm-status--' + esc(ticket.status || 'open') + '">' + esc(ticket.label || '') + '</span>';
                // Tickets open in the ticket modal; there is no ticket page.
                return SUP.ticket && ticket.id ? '<button type="button" class="rm-row rm-row--link" data-act="sup-ticket" data-id="' + esc(ticket.id) + '">' + inner + '</button>' : '<div class="rm-row">' + inner + '</div>';
            }).join('') + '</section>' : rmEmpty('receipt', supLabel('empty')));
        }
        if (sub === 'logs') {
            var all = SUP.logs ? '<a class="rm-logs-all" href="' + esc(SUP.logs + '?restaurants[]=' + encodeURIComponent(card.id)) + '">' + rmIcon('file') + '<span>' + esc(supLabel('viewAllLogs')) + '</span></a>' : '';
            // What went wrong lately. The successful requests stay on the logs page.
            var logs = supFeed(card.id, 'view=failed&hours=48&limit=200');
            return bar + all + '<p class="rm-lognote">' + esc(supLabel('failuresNote')) + '</p>' + (logs === null ? '<div class="ag-skel"><i></i><i></i><i></i></div>'
                : logs.length ? '<div class="rm-logs">' + logs.map(supLogRow).join('') + '</div>' : rmEmpty('file', supLabel('noFailures')));
        }
        // Devices: how many are live, then each recent device with its errors from the last 12 hours.
        // Ones not seen for days wait behind a button.
        if (!devices.length) return bar + rmEmpty('alert', t('oldPos'));
        var groups = {};
        if (withLogs) {
            var bugs = supFeed(card.id, 'view=bugs&hours=12&limit=200') || [];
            bugs.forEach(function (log) {
                var id = log.device && log.device.id;
                if (id) (groups[id] || (groups[id] = [])).push(log);
            });
        }
        var products = typeof card.products === 'string' ? card.products
            : (card.products || []).map(function (key) { return supLabel(key); }).filter(Boolean).join(' · ');
        var live = Number(card.online) > 0;
        var showOld = ui.supOldFor === row.id;
        var older = devices.filter(function (device) { return device.old; }).length;
        var shown = showOld ? devices : devices.filter(function (device) { return !device.old; });
        return bar + '<div class="rm-supsum' + (live ? ' is-live' : '') + '"><span class="rm-pulse" aria-hidden="true"><i></i></span><div>' +
            '<p>' + esc(supLabel('devicesOnline').replace(':online', card.online).replace(':total', card.total)) +
            (products ? ' · <bdi dir="ltr">' + esc(products) + '</bdi>' : '') + '</p>' +
            '<p class="rm-row__meta">' + esc(supLabel('last')) + ': ' + esc(card.lastSeen || supLabel('never')) + '</p>' +
            (card.error ? '<p class="rm-supsum__error">' + esc(card.error) + '</p>' : '') + '</div></div>' +
            (shown.length ? '<div class="rm-devs">' + shown.map(function (device) {
                var rows = groups[device.id] || [];
                return supDeviceRow(device, rows.length ? '<div class="rm-logs rm-logs--dev">' + rows.map(supLogRow).join('') + '</div>' : '', got.live ? card.id : '');
            }).join('') + '</div>' : rmEmpty('alert', supLabel('noRecentDevices'))) +
            (older ? '<button type="button" class="rm-logs-all rm-more" data-act="sup-old">' + rmIcon('file') + '<span>' + esc(showOld ? supLabel('hideOlder') : supLabel('olderDevices').replace(':count', older)) + '</span></button>' : '');
    }

    // The ticket modal lives in ticket-view.js, loaded the first time a ticket is opened.
    function openTicket(publicId) {
        var url = String(SUP.ticket).replace('__ID__', encodeURIComponent(publicId));
        if (window.TicketView) { window.TicketView.open(url); return; }
        if (!SUP.ticketScript) return;
        var script = document.createElement('script');
        script.src = SUP.ticketScript;
        script.onload = function () { if (window.TicketView) window.TicketView.open(url); };
        document.head.appendChild(script);
    }

    function embedUrl(clientId, sub) {
        return String(boot.financeEmbed).replace('__ID__', encodeURIComponent(clientId)) + '&tab=' + (sub === 'hardware' ? 'invoices' : 'payments');
    }

    // Loaded sections are kept as live nodes, so a re-render keeps what was typed and the listeners on it.
    var embeds = {};

    function takeInitialEmbed() {
        var initial = document.querySelector('template[data-fin-embed-initial]');
        if (!initial) return;
        var node = document.createElement('div');
        node.appendChild(initial.content.cloneNode(true));
        embeds[embedUrl(initial.getAttribute('data-client'), initial.getAttribute('data-tab') === 'invoices' ? 'hardware' : 'monthly')] = { node: node, ready: false };
        initial.remove();
    }

    function settleEmbed(entry) {
        if (entry.ready) return;
        entry.ready = true;
        var root = entry.node.querySelector('[data-fcm]');
        if (root && window.FinanceWorkspace) window.FinanceWorkspace.settle(root, null);
    }

    function embedSlot(url) {
        var slots = document.querySelectorAll('#ag-portal [data-fin-embed]');
        for (var i = 0; i < slots.length; i++) {
            if (slots[i].getAttribute('data-fin-embed') === url) return slots[i];
        }
        return null;
    }

    function hydrateEmbeds(dialog) {
        dialog.querySelectorAll('[data-fin-embed]').forEach(function (slot) {
            var url = slot.getAttribute('data-fin-embed');
            var entry = embeds[url];
            if (entry && entry.node) {
                slot.appendChild(entry.node);
                settleEmbed(entry);
                return;
            }
            slot.innerHTML = '<div class="ag-skel"><i></i><i></i></div>';
            if (entry && entry.loading) return;
            embeds[url] = { loading: true };
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (response) { if (!response.ok) throw new Error(String(response.status)); return response.text(); })
                .then(function (html) {
                    var node = document.createElement('div');
                    node.innerHTML = html;
                    embeds[url] = { node: node, ready: false };
                    var live = embedSlot(url);
                    if (live) {
                        live.innerHTML = '';
                        live.appendChild(node);
                        settleEmbed(embeds[url]);
                    }
                })
                .catch(function () {
                    delete embeds[url];
                    var live = embedSlot(url);
                    if (live) live.innerHTML = '<p class="rm-empty">' + rmIcon('alert') + '<span>' + esc(t('loadFailed')) + '</span></p>';
                });
        });
    }

    // Older saved data put the restaurant's own site address into its notes; that is not a note.
    function cleanNotes(row) {
        return String(row.notes || '').split('\n').filter(function (line) {
            var clean = line.trim();
            return clean !== '' && clean !== row.site && !/^https?:\/\/\S*kaman\.rest\/?$/i.test(clean);
        }).join('\n');
    }

    function loginAsUrl(row) {
        var id = String((row && row.id) || '');
        if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(id)) return '';
        return 'https://admin.kaman.rest/admin/companies/login-as/' + encodeURIComponent(id);
    }

    // The restaurant's settings page in the Kaman admin (opens on the General tab).
    function adminSettingsUrl(row) {
        var id = String((row && row.id) || '');
        if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(id)) return '';
        return 'https://admin.kaman.rest/admin/companies/' + encodeURIComponent(id) + '/edit#general';
    }

    function rmEmpty(icon, label) {
        return '<p class="rm-empty">' + rmIcon(icon) + '<span>' + esc(label) + '</span></p>';
    }

    function rmIcon(name) {
        var paths = {
            enter: '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/>',
            globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
            edit: '<path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/>',
            wallet: '<path d="M4 7h15a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7z"/><path d="M4 7l11-3v3"/><circle cx="16" cy="13.5" r="1.2"/>',
            alert: '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5M12 16h.01"/>',
            calendar: '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/>',
            receipt: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z"/><path d="M9 8h6M9 12h6"/>',
            file: '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/>',
            gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>'
        };
        return '<svg class="rm-ico" viewBox="0 0 24 24" aria-hidden="true">' + (paths[name] || '') + '</svg>';
    }

    function kv(label, value) {
        return '<div class="ag-kv"><span>' + esc(label) + '</span><b>' + esc(value || '—') + '</b></div>';
    }

    function renderAppt() {
        if (!ui.apptId) return '';
        var row = ui.apptId === 'new' ? { agentId: ui.calAgent || ME, at: ui.apptAt || shift(0, 10), type: 'visit', status: 'scheduled', notes: '', leadId: ui.apptLead || '', restaurantId: '' } : calendarRepository.find(ui.apptId);
        if (!row) { ui.apptId = null; return ''; }
        var choices = state.leads.map(function (lead) {
            return '<option value="lead:' + lead.id + '"' + (row.leadId === lead.id ? ' selected' : '') + '>' + esc(text(lead.name)) + '</option>';
        }).join('') + state.restaurants.map(function (restaurant) {
            return '<option value="restaurant:' + restaurant.id + '"' + (row.restaurantId === restaurant.id ? ' selected' : '') + '>' + esc(text(restaurant.name)) + '</option>';
        }).join('');
        return '<form class="ag-dialog" data-dialog data-form="appt" role="document"><h2 id="ag-portal-title">' + esc(ui.apptId === 'new' ? t('newAppt') : apptTitle(row)) + '</h2>' +
            '<label class="ag-field">' + esc(t('restaurant')) + '<select data-ds-select name="target" required>' + choices + '</select></label>' +
            '<label class="ag-field">' + esc(t('agent')) + '<select data-ds-select name="agentId">' + selectAgents(row.agentId) + '</select></label>' +
            '<label class="ag-field">' + esc(t('date')) + '<input type="datetime-local" name="at" required value="' + esc(toInput(row.at)) + '"></label>' +
            '<div class="ag-grid-2"><label class="ag-field">' + esc(t('type')) + '<select data-ds-select name="type">' + options(['visit', 'demo', 'call', 'followup', 'onboarding'], row.type) + '</select></label>' +
            '<label class="ag-field">' + esc(t('status')) + '<select data-ds-select name="status">' + options(APPT_STATUS, row.status) + '</select></label></div>' +
            '<label class="ag-field">' + esc(t('notes')) + '<textarea name="notes">' + esc(row.notes || '') + '</textarea></label>' +
            '<div class="ag-actions"><button class="ag-btn" type="submit">' + esc(t('save')) + '</button>' +
            (ui.apptId !== 'new' && row.status !== 'done' ? '<button class="ag-btn ghost ag-btn--done" type="button" data-act="appt-done" data-id="' + esc(ui.apptId) + '">' + esc(t('markDone')) + '</button>' : '') +
            '<button class="ag-btn ghost" type="button" data-act="close">' + esc(t('cancel')) + '</button></div></form>';
    }

    function renderConfirm() {
        if (!ui.confirm) return '';
        var copy = ui.confirm.kind === 'delete-lead' ? t('confirmDelete') : t('confirmConvert');
        return '<div class="ag-dialog" data-dialog role="document"><h2 id="ag-portal-title">' + esc(copy) + '</h2><div class="ag-actions"><button type="button" class="ag-btn" data-act="confirm-yes">' + esc(t('yes')) + '</button><button type="button" class="ag-btn ghost" data-act="close">' + esc(t('no')) + '</button></div></div>';
    }

    function renderClientForm() {
        if (!ui.clientDraft) return '';
        return '<form class="ag-dialog" data-dialog data-form="new-client" role="document"><h2 id="ag-portal-title">' + esc(t('newClient')) + '</h2><div class="ag-grid-2">' + formFields({ agentId: ME, cityId: CITIES[0].id }) + '</div><div class="ag-actions"><button class="ag-btn" type="submit">' + esc(t('create')) + '</button><button class="ag-btn ghost" type="button" data-act="close">' + esc(t('cancel')) + '</button></div></form>';
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
                // Lift loaded Finance sections out first: innerHTML would empty them.
                dialog.querySelectorAll('[data-fin-embed] > div').forEach(function (node) { node.remove(); });
                dialog.innerHTML = html;
                hydrateEmbeds(dialog);
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
        if (boot.surface === 'finance' || boot.surface === 'support') return;
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

    document.addEventListener('pointermove', function (event) {
        var card = event.target.closest && event.target.closest('.rest-card');
        if (!card) return;
        var box = card.getBoundingClientRect();
        card.style.setProperty('--mx', Math.round(((event.clientX - box.left) / box.width) * 100) + '%');
    });

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
        if (act === 'fin-tab') { ui.finTab = actEl.dataset.tab; render(); return; }
        if (act === 'sup-tab') { ui.supTab = actEl.dataset.tab; render(); return; }
        if (act === 'sup-old') { ui.supOldFor = ui.supOldFor === ui.restaurantId ? null : ui.restaurantId; render(); return; }
        if (act === 'sup-ticket') { openTicket(id); return; }
        if (act === 'sup-printers') {
            if (window.SupportBoard) window.SupportBoard.openPrinters(actEl.dataset.slug, actEl.dataset.device);
            return;
        }
        if (act === 'open-support') {
            if (!id) return;
            ui.restaurantId = id; ui.tab = 'support'; ui.supTab = 'devices'; ui.editing = false; ui.bell = false; render(); return;
        }
        if (act === 'open-finance') {
            // A Finance client row: its own buttons and links (WhatsApp notice) keep working.
            if (event.target.closest('a, button, form, [data-fin-wa]')) return;
            ui.restaurantId = id; ui.tab = 'finance'; ui.finTab = 'monthly'; ui.editing = false; ui.bell = false; render(); return;
        }
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
                    openBoarding(leadRepository.find(pending.id));
                    leadRepository.convert(pending.id);
                    ui.leadId = null;
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
        if (act === 'page') { ui.pageNum = Number(actEl.dataset.n); render(); return; }
        if (act === 'appt-done') {
            safe(function () {
                calendarRepository.update(actEl.dataset.id, { status: 'done' });
                ui.apptId = null;
                toast(t('toastSaved'));
            });
            render();
        }
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
                var patch = { name: data.name, contact: data.contact, phone: data.phone, cityId: data.cityId, agentId: data.agentId, stage: data.stage };
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
        if (event.key === 'Enter' && event.target.closest('[data-act="open-lead"], [data-act="open-client"], [data-act="open-finance"]')) event.target.click();
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
        if (boot.surface === 'finance' || boot.surface === 'support') return;
        var part = location.pathname.split('/').filter(Boolean).pop();
        if (boot.surface === 'overview') ui.page = part === 'restaurants' ? 'restaurants' : 'dashboard';
        else ui.page = part === 'calendar' ? 'calendar' : 'leads';
        render();
    });

    if (boot.open && boot.open.id) {
        // Opened from a link (?open=…), or back from a Finance save: open the restaurant on that door's section.
        takeInitialEmbed();
        ui.restaurantId = boot.open.id;
        ui.tab = boot.open.tab || 'finance';
        ui.finTab = boot.open.finTab === 'hardware' ? 'hardware' : 'monthly';
        ui.supTab = 'devices';
        if (window.history && window.history.replaceState) {
            var clean = new URL(window.location.href);
            clean.searchParams.delete('open');
            clean.searchParams.delete('tab');
            window.history.replaceState(null, '', clean.toString());
        }
    }

    listeners.push(function () { render(); });
    render();
    if (ui.booting) setTimeout(function () { ui.booting = false; render(); }, 160);

    if (crm) {
        writes = importLocal().then(pull).then(settle).catch(function () {
            toast(t('loadFailed'));
            settle();
        });
        // Another device may have changed something while this tab sat in the background.
        window.addEventListener('focus', function () {
            if (crmLoaded && !dialogOpen()) crmSync();
        });
    }

    window.KamanAgents = {
        restaurantRepository: restaurantRepository,
        leadRepository: leadRepository,
        calendarRepository: calendarRepository,
        featuresRepository: featuresRepository,
        financeRepository: financeRepository,
        supportRepository: supportRepository,
        refresh: function () { renderLayer(); }
    };
})();
