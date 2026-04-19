<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>كمان | هيا نبدأ</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&display=swap" rel="stylesheet">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Tajawal', sans-serif;
            background: #ffffff;
            color: #1a1a1a;
            min-height: 100vh;
            overflow-x: hidden;
        }


        /* Form Inputs */
        .form-input {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #ffffff;
            font-size: 1rem;
            transition: all 0.2s ease;
            font-family: 'Heebo', sans-serif;
        }

        .form-input:focus {
            outline: none;
            border-color: #f59a2a;
            box-shadow: 0 0 0 3px rgba(245, 154, 42, 0.1);
        }

        .form-label {
            display: block;
            font-weight: 500;
            font-size: 0.875rem;
            color: #374151;
            margin-bottom: 0.5rem;
        }

        .required-star {
            color: #f59a2a;
        }

        /* Step Header */
        .step-header {
            margin-bottom: 2rem;
        }

        .step-number {
            color: #f59a2a;
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .step-title {
            font-size: 2rem;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 0.5rem;
        }

        /* Submit Button */
        .submit-button {
            width: 100%;
            padding: 1rem 2rem;
            background: #f59a2a;
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: 'Heebo', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .submit-button:hover {
            background: #f2891f;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(245, 154, 42, 0.3);
        }

        .submit-button:active {
            transform: translateY(0);
        }

        /* Logo */
        .logo-icon {
            width: 48px;
            height: 48px;
            background: #ffffff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            margin-bottom: 2rem;
        }

        .logo-icon::after {
            content: '';
            position: absolute;
            top: -4px;
            right: -4px;
            width: 16px;
            height: 16px;
            background: #f59a2a;
            border-radius: 4px;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .step-title {
                font-size: 1.5rem;
            }

            .form-container {
                padding: 1.5rem;
            }
        }

        /* Success/Error Messages */
        .success-message {
            background: #f0fdf4;
            border: 1px solid #86efac;
            color: #166534;
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
        }

        .error-message {
            background: #fef2f2;
            border: 1px solid #fca5a5;
            color: #991b1b;
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
        }

        /* Horizontal Banner Logos */
        .banner-container {
            overflow: hidden;
            position: relative;
            width: 100%;
            margin-top: 2rem;
        }

        .banner-track {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 1.5rem;
            width: max-content;
            flex-wrap: nowrap;
            animation: banner-scroll-horizontal var(--marquee-duration, 60s) linear infinite;
            will-change: transform;
        }

        .banner-track .shrink-0 {
            flex: 0 0 auto;
        }

        .banner-logo-card {
            width: 80px;
            height: 80px;
            border-radius: 16px;
            border: 1px solid rgba(245, 154, 42, 0.2);
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            position: relative;
        }

        .banner-logo-circle {
            height: 64px;
            width: 64px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: 
                inset 0 2px 4px rgba(0, 0, 0, 0.1),
                inset 0 -1px 2px rgba(0, 0, 0, 0.05),
                0 1px 2px rgba(0, 0, 0, 0.1);
            position: relative;
            margin: 0 auto;
        }

        .banner-logo-circle img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 999px;
        }

        @keyframes banner-scroll-horizontal {
            0% {
                transform: translateX(0);
            }
            100% {
                transform: translateX(var(--marquee-distance, -50%));
            }
        }

        .banner-wrapper {
            position: relative;
            overflow: hidden;
            border-radius: 12px;
            padding: 1rem;
            background: #f9fafb;
        }

        .banner-wrapper::before,
        .banner-wrapper::after {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            width: 50px;
            z-index: 10;
            pointer-events: none;
        }

        .banner-wrapper::before {
            right: 0;
            background: linear-gradient(to left, #f9fafb, transparent);
        }

        .banner-wrapper::after {
            left: 0;
            background: linear-gradient(to right, #f9fafb, transparent);
        }
    </style>
</head>
<body>
    <div class="min-h-screen">
        <!-- Form Content -->
        <div class="flex flex-col">
1            <div class="form-container max-w-2xl mx-auto w-full px-4 sm:px-6 py-6 sm:py-8 lg:py-12">
                <!-- Step Header -->
                <div class="step-header">
                    <div class="step-number">هيا نبدأ</div>
                    <h1 class="step-title">معلومات الاتصال</h1>
                </div>

                <!-- Success/Error Messages -->
                @if(session('success'))
                    <div class="success-message">
                        {{ session('success') }}
                    </div>
                @endif

                @if(session('error'))
                    <div class="error-message">
                        {{ session('error') }}
                    </div>
                @endif


                <!-- Single Page Form -->
                <form method="POST" action="{{ route('landing.submit') }}" id="contactForm" class="space-y-5">
                    @csrf

                    <div>
                        <label for="full_name" class="form-label">
                            الاسم الكامل <span class="required-star">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="full_name" 
                            name="full_name" 
                            class="form-input"
                            value="{{ old('full_name') }}"
                            required
                        >
                    </div>

                    <div>
                        <label for="phone" class="form-label">
                            رقم الهاتف <span class="required-star">*</span>
                        </label>
                        <input 
                            type="tel" 
                            id="phone" 
                            name="phone" 
                            class="form-input"
                            value="{{ old('phone') }}"
                            placeholder="05XXXXXXXX"
                            dir="ltr"
                            required
                        >
                    </div>

                    <div>
                        <label for="email" class="form-label">
                            البريد الإلكتروني
                        </label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="form-input"
                            value="{{ old('email') }}"
                            placeholder="example@email.com (اختياري)"
                            dir="ltr"
                        >
                    </div>

                    <div>
                        <label for="country" class="form-label">
                            الموقع <span class="required-star">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="country" 
                            name="country" 
                            class="form-input"
                            value="{{ old('country') }}"
                            placeholder="المدينة أو المنطقة"
                            required
                        >
                    </div>

                    <div>
                        <label for="restaurant_name" class="form-label">
                            اسم المطعم
                        </label>
                        <input 
                            type="text" 
                            id="restaurant_name" 
                            name="restaurant_name" 
                            class="form-input"
                            value="{{ old('restaurant_name') }}"
                            placeholder="(اختياري)"
                        >
                    </div>

                    <div>
                        <label class="form-label">
                            حالة المطعم <span class="required-star">*</span>
                        </label>
                        <div class="space-y-3 mt-2">
                            <label class="flex items-start gap-3 p-4 border-2 border-gray-200 rounded-xl cursor-pointer hover:border-orange-300 transition-all">
                                <input 
                                    type="radio" 
                                    name="restaurant_status" 
                                    value="new_restaurant"
                                    class="mt-1"
                                    {{ old('restaurant_status', 'new_restaurant') === 'new_restaurant' ? 'checked' : '' }}
                                    required
                                >
                                <div>
                                    <div class="font-semibold">مطعم جديد</div>
                                    <div class="text-sm text-gray-600">أخطط لفتح مطعم</div>
                                </div>
                            </label>
                            <label class="flex items-start gap-3 p-4 border-2 border-gray-200 rounded-xl cursor-pointer hover:border-orange-300 transition-all">
                                <input 
                                    type="radio" 
                                    name="restaurant_status" 
                                    value="operating_restaurant"
                                    class="mt-1"
                                    {{ old('restaurant_status') === 'operating_restaurant' ? 'checked' : '' }}
                                >
                                <div>
                                    <div class="font-semibold">مطعم نشط</div>
                                    <div class="text-sm text-gray-600">لدي مطعم نشط</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="submit-button">
                        إرسال الطلب
                    </button>
                </form>

                <!-- Horizontal Scrolling Banner -->
                @if(!empty($bannerLogos ?? []))
                    <div class="banner-wrapper mt-8">
                        <div class="banner-container">
                            <div class="banner-track">
                                @foreach($bannerLogos as $logo)
                                    <div class="shrink-0 flex items-center justify-center">
                                        <div class="banner-logo-card">
                                            <div class="banner-logo-circle">
                                                <img src="{{ $logo['url'] }}" alt="{{ $logo['name'] }}">
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                                @foreach($bannerLogos as $logo)
                                    <div class="shrink-0 flex items-center justify-center">
                                        <div class="banner-logo-card">
                                            <div class="banner-logo-circle">
                                                <img src="{{ $logo['url'] }}" alt="{{ $logo['name'] }}">
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                                @foreach($bannerLogos as $logo)
                                    <div class="shrink-0 flex items-center justify-center">
                                        <div class="banner-logo-card">
                                            <div class="banner-logo-circle">
                                                <img src="{{ $logo['url'] }}" alt="{{ $logo['name'] }}">
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <script>
        // Horizontal Marquee infinite loop calculation
        (function initHorizontalMarquee() {
            const track = document.querySelector('.banner-track');
            if (!track) return;

            const rootDir = document.documentElement.getAttribute('dir') || 'ltr';

            async function waitForImages() {
                const imgs = Array.from(track.querySelectorAll('img'));
                await Promise.all(
                    imgs.map(img => {
                        if (img.complete && img.naturalWidth > 0) return Promise.resolve();
                        if (img.decode) return img.decode().catch(() => {});
                        return new Promise(res => {
                            img.addEventListener('load', res, { once: true });
                            img.addEventListener('error', res, { once: true });
                        });
                    })
                );
            }

            function calculate() {
                const totalWidth = track.scrollWidth;
                const halfWidth = totalWidth / 2;
                // RTL: reverse direction, LTR: normal direction
                const distance = (rootDir === 'rtl') ? +halfWidth : -halfWidth;
                track.style.setProperty('--marquee-distance', `${distance}px`);
                const pxPerSecond = 40;
                const duration = Math.max(10, Math.round(Math.abs(halfWidth) / pxPerSecond));
                track.style.setProperty('--marquee-duration', `${duration}s`);
            }

            async function setup() {
                await waitForImages();
                requestAnimationFrame(() => {
                    calculate();
                    setTimeout(calculate, 100);
                });
            }

            setup();

            let t;
            window.addEventListener('resize', () => {
                clearTimeout(t);
                t = setTimeout(() => {
                    calculate();
                }, 150);
            });
        })();
    </script>
</body>
</html>

