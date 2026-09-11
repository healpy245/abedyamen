<?php

declare(strict_types=1);

namespace App\Services\AiChatbot;

/**
 * Cellular visa terminal is product knowledge only.
 * Never volunteer it; reply with this sentence only when the customer asked.
 */
class KamanVisaDeviceFact
{
    public const REPLY = 'اه احنا بنبيع مخشير فيزا بشتغل على الـ4G وسلولري. يعني لو طفى الكهرب أو النت بضل شغال عادي.';

    public function customerAsked(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        if (preg_match('/\{\{[A-Z0-9_]+\}\}/', $text)
            && ! preg_match('/مخشير|מכשיר|فيزا|ויזה|visa/iu', $text)
        ) {
            return false;
        }

        return (bool) preg_match(
            '/مخشير.{0,20}فيزا|فيزا.{0,16}(سلولري|סלולרי|4G)|מכשיר.{0,24}(ויזה|فيزا|visa|4G|סלולרי)|جهاز.{0,16}فيزا|(?:visa|ויזה).{0,16}(4G|سلولري|סלולרי)/iu',
            $text
        );
    }
}
