<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Throwable;

class AgentsWorkspaceController extends Controller
{
    public function show(Request $request, ?string $page = null): View|RedirectResponse
    {
        $user = $request->user();
        if ($user !== null && $user->isChatbotOnly()) {
            return redirect($user->afterLoginUrl());
        }

        $page = $page ?: 'leads';
        if ($page === 'clients') {
            return redirect()->route('workspace.overview', ['page' => 'restaurants']);
        }
        abort_unless(in_array($page, ['leads', 'calendar'], true), 404);

        return view('workspace.agents.index', [
            'page' => $page,
            'companies' => $this->companies(),
            'cities' => $this->cities(),
        ]);
    }

    /**
     * @return array{companies: list<array<string, string>>, cities: list<array<string, string>>}
     */
    public function catalog(): array
    {
        return [
            'companies' => $this->companies(),
            'cities' => $this->cities(),
        ];
    }

    /**
     * Live restaurants from https://kaman.rest/api/companies.
     *
     * @return list<array{id:string,name:string,subdomain:string,url:string,color:string,lang:string,address:string,logo:string,hours:string,cityId:string}>
     */
    private function companies(): array
    {
        $cached = Cache::get('kaman.rest.companies.v2');
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        try {
            $response = Http::timeout(8)->acceptJson()->get('https://kaman.rest/api/companies');
        } catch (Throwable) {
            return [];
        }

        $rows = $response->ok() ? $response->json('data.companies') : null;
        if (! is_array($rows)) {
            return [];
        }

        $cities = $this->cities();
        $companies = [];
        foreach ($rows as $row) {
            if (! is_array($row) || trim((string) ($row['name'] ?? '')) === '') {
                continue;
            }
            $settings = is_array($row['settings'] ?? null) ? $row['settings'] : [];
            $name = (string) $row['name'];
            $subdomain = (string) ($row['subdomain'] ?? '');
            $address = (string) ($settings['address'] ?? '');
            $companies[] = [
                'id' => (string) ($row['id'] ?? ''),
                'name' => $name,
                'subdomain' => $subdomain,
                'url' => (string) ($row['full_path'] ?? ''),
                'color' => (string) ($settings['color'] ?? ''),
                'lang' => (string) ($settings['lang'] ?? ''),
                'address' => $address,
                'logo' => (string) ($settings['logo'] ?? ''),
                'hours' => (string) ($settings['working_hours'] ?? ''),
                'cityId' => $this->matchCity($name, $subdomain, $address, $cities),
            ];
        }

        if ($companies !== []) {
            Cache::put('kaman.rest.companies.v2', $companies, 300);
        }

        return $companies;
    }

    /**
     * @return list<array{id:string,en:string,he:string,ar:string}>
     */
    private function cities(): array
    {
        $cached = Cache::get('kaman.rest.company-areas');
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        try {
            $response = Http::timeout(8)->acceptJson()->get('https://kaman.rest/api/company-areas');
        } catch (Throwable) {
            return [];
        }

        $rows = $response->ok() ? $response->json('data.areas') : null;
        if (! is_array($rows)) {
            return [];
        }

        $cities = [];
        foreach ($rows as $row) {
            if (! is_array($row) || trim((string) ($row['name_ar'] ?? '')) === '') {
                continue;
            }
            $names = $this->areaNames($row);
            $cities[] = [
                'id' => (string) ($row['id'] ?? ''),
                'en' => $names['en'],
                'he' => $names['he'],
                'ar' => $names['ar'],
            ];
        }

        if ($cities !== []) {
            Cache::put('kaman.rest.company-areas', $cities, 300);
        }

        return $cities;
    }

    /**
     * @param  array<string, mixed>  $area
     * @return array{en:string,he:string,ar:string}
     */
    private function areaNames(array $area): array
    {
        $ar = trim((string) ($area['name_ar'] ?? ''));
        $candidates = array_filter([
            $ar,
            trim((string) ($area['name_en'] ?? '')),
            trim((string) ($area['name_he'] ?? '')),
        ]);
        $en = '';
        $he = '';
        foreach ($candidates as $value) {
            if ($he === '' && preg_match('/\p{Hebrew}/u', $value) === 1) {
                $he = $value;
            } elseif ($en === '' && preg_match('/\p{Arabic}|\p{Hebrew}/u', $value) !== 1 && preg_match('/[A-Za-z]/', $value) === 1) {
                $en = $value;
            }
        }

        return [
            'ar' => $ar,
            'en' => $en !== '' ? $en : $ar,
            'he' => $he !== '' ? $he : $ar,
        ];
    }

    /**
     * @param  list<array{id:string,en:string,he:string,ar:string}>  $cities
     */
    private function matchCity(string $name, string $subdomain, string $address, array $cities): string
    {
        $blob = $this->compact($name.' '.$subdomain.' '.$address);
        $host = $this->compact($subdomain);
        $bestId = '';
        $bestLength = 0;

        foreach ($cities as $city) {
            $needles = array_filter([
                $this->compact($city['ar']),
                $this->compact($city['en']),
                $this->compact($city['he']),
            ]);
            foreach ($this->aliases($this->compact($city['ar'])) as $alias) {
                $needles[] = $alias;
            }
            foreach ($needles as $needle) {
                if ($needle === '' || mb_strlen($needle) < 2) {
                    continue;
                }
                $hit = mb_strlen($needle) >= 4
                    ? mb_strpos($blob, $needle) !== false
                    : ($host !== '' && str_ends_with($host, $needle));
                if (! $hit) {
                    continue;
                }
                $length = mb_strlen($needle);
                if ($length > $bestLength) {
                    $bestLength = $length;
                    $bestId = $city['id'];
                }
            }
        }

        return $bestId;
    }

    /**
     * Spellings used in restaurant names that are not the area's stored name.
     *
     * @return list<string>
     */
    private function aliases(string $compactArabic): array
    {
        return match ($compactArabic) {
            'كفرقاسم' => ['kq'],
            'كفرقرع' => ['qara'],
            'طيبه' => ['taybe'],
            'كفرمندا' => ['kafrmanda', 'farmanda'],
            'عرابه' => ['araba', 'arabeh', 'arraba'],
            'شفاعمرو' => ['shfarm'],
            'الرينه' => ['reineh'],
            default => [],
        };
    }

    private function compact(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = str_replace(['أ', 'إ', 'آ', 'ٱ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ا', 'ه', 'ي'], $value);
        $value = preg_replace('/\p{M}/u', '', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? $value;

        return $value;
    }
}
