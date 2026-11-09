<?php

declare(strict_types=1);

namespace App\Enums\Tranzila;

/**
 * The cc_brand codes of the document API. Hosted Fields numbers its card_type differently from code 6 on,
 * so brands are matched by name and never by copying a number across.
 */
enum CardBrand: int
{
    case Mastercard = 1;
    case Visa = 2;
    case Diners = 3;
    case Amex = 4;
    case Isracard = 5;
    case Jcb = 6;
    case Discover = 7;
    case Maestro = 8;

    public function label(): string
    {
        return __('tranzila.card_brand.'.$this->value);
    }

    public static function fromName(?string $name): ?self
    {
        $name = mb_strtolower((string) $name);

        $brands = [
            'master' => self::Mastercard,
            'visa' => self::Visa,
            'diners' => self::Diners,
            'amex' => self::Amex,
            'american' => self::Amex,
            'isracard' => self::Isracard,
            'jcb' => self::Jcb,
            'discover' => self::Discover,
            'maestro' => self::Maestro,
        ];

        foreach ($brands as $needle => $brand) {
            if (str_contains($name, $needle)) {
                return $brand;
            }
        }

        return null;
    }
}
