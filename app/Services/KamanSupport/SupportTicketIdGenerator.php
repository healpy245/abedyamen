<?php

declare(strict_types=1);

namespace App\Services\KamanSupport;

use App\Models\KamanSupport\SupportTicket;
use Illuminate\Support\Str;

class SupportTicketIdGenerator
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    public function generate(): string
    {
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $id = 'KAM-'.$suffix;

            if (! SupportTicket::query()->where('public_id', $id)->exists()) {
                return $id;
            }
        }

        return 'KAM-'.strtoupper(Str::random(6));
    }

    public function normalize(?string $clientTicketId): ?string
    {
        $id = trim((string) $clientTicketId);

        if ($id === '') {
            return null;
        }

        return mb_substr($id, 0, 64);
    }
}
