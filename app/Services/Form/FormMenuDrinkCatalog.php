<?php

declare(strict_types=1);

namespace App\Services\Form;

use Illuminate\Support\Facades\File;

final class FormMenuDrinkCatalog
{
    /**
     * @var array<string, string>|null
     */
    private ?array $index = null;

    /**
     * @param  list<string>  $names
     */
    public function imageFor(array $names): ?string
    {
        $catalog = $this->index();
        if ($catalog === []) {
            return null;
        }

        $keys = [];
        foreach ($names as $name) {
            $key = $this->key($name);
            if ($key !== '' && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        foreach ($keys as $key) {
            if (isset($catalog[$key])) {
                return $catalog[$key];
            }
        }

        $aliases = array_keys($catalog);
        usort($aliases, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($keys as $key) {
            foreach ($aliases as $alias) {
                if (strlen($alias) < 4) {
                    continue;
                }
                if (str_starts_with($key, $alias) || str_starts_with($alias, $key)) {
                    return $catalog[$alias];
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $this->index = [];
        foreach (['ColdDrinks', 'HotDrinks', 'NaturalJuice'] as $folder) {
            $dir = public_path($folder);
            if (! File::isDirectory($dir)) {
                continue;
            }
            foreach (File::files($dir) as $file) {
                $ext = strtolower($file->getExtension());
                if (! in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
                    continue;
                }
                $path = $file->getPathname();
                $stem = pathinfo($file->getFilename(), PATHINFO_FILENAME);
                foreach ($this->keysForStem($stem) as $key) {
                    if ($key !== '' && ! isset($this->index[$key])) {
                        $this->index[$key] = $path;
                    }
                }
            }
        }

        return $this->index;
    }

    /**
     * @return list<string>
     */
    private function keysForStem(string $stem): array
    {
        $keys = [];
        $push = function (string $value) use (&$keys): void {
            $key = $this->key($value);
            if ($key !== '' && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        };

        $push($stem);
        $push(str_replace(['-', '_'], ' ', $stem));

        $normalized = $this->key($stem);
        foreach ($this->aliases() as $alias => $extra) {
            $aliasKey = $this->key($alias);
            if ($aliasKey !== '' && ($normalized === $aliasKey || str_contains($normalized, $aliasKey) || str_contains($aliasKey, $normalized))) {
                $push($alias);
                foreach ($extra as $name) {
                    $push($name);
                }
            }
        }

        return $keys;
    }

    /**
     * @return array<string, list<string>>
     */
    private function aliases(): array
    {
        return [
            'cola' => ['cola', 'coca cola', 'cocacola', 'كولا', 'كوكا كولا', 'קולה'],
            'cola zero' => ['cola zero', 'coca cola zero', 'كولا زيرو', 'קולה זירו'],
            'sprite' => ['sprite', 'سبرايت', 'ספרייט'],
            'sprite zero' => ['sprite zero', 'سبرايت زيرو', 'ספרייט זירו'],
            'pepsi' => ['pepsi', 'بيبسي', 'פפסי'],
            'fanta' => ['fanta', 'فانتا', 'פאנטה'],
            'red bull' => ['red bull', 'redbull', 'ريد بول', 'רד בול'],
            '7up' => ['7up', 'seven up', 'سفن أب', 'סבן אפ'],
            'water' => ['water', 'mineral water', 'aqua', 'ماء', 'مياه', 'מים'],
            'xl' => ['xl', 'إكس إل', 'אקס אל'],
            'fuze tea' => ['fuze tea', 'fuzetea', 'فوز تي', 'פיוז טי'],
            'lemonade' => ['lemonade', 'ليمونادة', 'לימונדה'],
            'nescafe' => ['nescafe', 'nescafé', 'نسكافيه', 'נסקפה'],
            'espresso' => ['espresso', 'اسبريسو', 'אספרסו'],
            'americano' => ['americano', 'امريكانو', 'אמריקנו'],
            'cappuccino' => ['cappuccino', 'كابتشينو', 'קפוצ\'ינו'],
            'latte' => ['latte', 'لاتيه', 'לאטה'],
            'mocha' => ['mocha', 'موكا', 'מוקה'],
        ];
    }

    private function key(string $name): string
    {
        $name = trim(mb_strtolower($name));
        $name = strtr($name, [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ة' => 'ه',
            'ى' => 'ي',
            'ؤ' => 'و',
            'ئ' => 'ي',
        ]);
        $name = preg_replace('/^ال/u', '', $name) ?? $name;

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $name) ?? $name;
    }
}
