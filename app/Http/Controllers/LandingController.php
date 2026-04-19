<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LandingController extends Controller
{
    public function show()
    {
        $bannerDirectory = public_path('banner');
        $bannerLogos = [];
        $adsDirectory = public_path('ads');
        $adsSlides = [];

        if (File::exists($bannerDirectory)) {
            $files = File::files($bannerDirectory);

            foreach ($files as $file) {
                $filename = $file->getFilename();
                $name = pathinfo($filename, PATHINFO_FILENAME);
                $label = ucwords(str_replace(['-', '_'], ' ', $name));

                $relativePath = str_replace(public_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $relativePath = str_replace('\\', '/', $relativePath);

                $bannerLogos[] = [
                    'name' => $label,
                    'url' => asset($relativePath),
                ];
            }
        }

        if (File::exists($adsDirectory)) {
            $files = File::files($adsDirectory);

            foreach ($files as $file) {
                $filename = $file->getFilename();
                $name = pathinfo($filename, PATHINFO_FILENAME);
                $label = ucwords(str_replace(['-', '_'], ' ', $name));

                $relativePath = str_replace(public_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $relativePath = str_replace('\\', '/', $relativePath);

                $adsSlides[] = [
                    'name' => $label,
                    'url' => asset($relativePath),
                ];
            }
        }

        return view('landing', [
            'bannerLogos' => $bannerLogos,
            'adsSlides' => $adsSlides,
            'contactInfo' => [
                'phone' => '0733220019',
                'email' => 'info@mfitgroup.com',
                'instagram' => 'https://www.instagram.com/kaman_by_mifit?utm_source=ig_web_button_share_sheet&igsh=ZDNlZDc0MzIxNw==',
                'facebook' => 'https://www.facebook.com/profile.php?id=61578729837778',
            ],
        ]);
    }

    public function submit(Request $request)
    {
        // Validate form fields
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'country' => ['required', 'string', 'max:120'],
            'restaurant_name' => ['nullable', 'string', 'max:255'],
            'restaurant_status' => ['required', 'in:new_restaurant,operating_restaurant'],
        ]);

        $fullName = trim($validated['full_name']);

        // Existing MFIT landing-leads payload
        $landingPayload = [
            'first_name' => $fullName .
                (!empty($validated['restaurant_name']) ? ' (' . $validated['restaurant_name'] . ')' : ''),
            'phone' => $validated['phone'],
            'city' => $validated['country'],
            'restaurant_status' => $validated['restaurant_status'],
        ];

        $mfitLandingUrl = 'https://mfit.karmelfiber.com/api/v1/landing-leads';
        $landingSuccess = false;
        try {
            $landingResponse = Http::timeout(10)->post($mfitLandingUrl, $landingPayload);
            $landingSuccess = $landingResponse->successful();
        } catch (\Throwable $e) {
            Log::warning('Failed to send MFIT landing-leads payload', [
                'url' => $mfitLandingUrl,
                'payload' => $landingPayload,
                'error' => $e->getMessage(),
            ]);
        }

        // New MFIT lead endpoint payload
        $restaurantStatusLabel = $validated['restaurant_status'] === 'new_restaurant'
            ? 'restaurant new'
            : 'restaurant old';

        $nameForLead = $fullName;
        if (!empty($validated['restaurant_name'])) {
            $nameForLead .= ' (' . $validated['restaurant_name'] . ', ' . $restaurantStatusLabel . ')';
        } else {
            $nameForLead .= ' (' . $restaurantStatusLabel . ')';
        }

        $leadPayload = [
            'name' => $nameForLead,
            'phone' => $validated['phone'],
            'status' => 4,
            'city' => $validated['country'],
        ];

        $mfitLeadUrl = 'https://mfit.karmelfiber.com/new-lead-mfit';
        $leadSuccess = false;
        try {
            $leadResponse = Http::timeout(10)
                ->withoutVerifying()
                ->post($mfitLeadUrl, $leadPayload);
            $leadSuccess = $leadResponse->successful();
        } catch (\Throwable $e) {
            Log::warning('Failed to send MFIT new-lead-mfit payload', [
                'url' => $mfitLeadUrl,
                'payload' => $leadPayload,
                'error' => $e->getMessage(),
            ]);
        }

        // Consider it successful if at least one API call succeeded
        if ($landingSuccess || $leadSuccess) {
            return redirect()
                ->route('landing.show')
                ->with('success', 'شكراً لانضمامك إلى كمان! تواصلنا معك قريباً.');
        }

        return redirect()
            ->route('landing.show')
            ->with('error', 'حدث خطأ أثناء إرسال البيانات إلى كمان. يرجى المحاولة مرة أخرى.');
    }
}
