<?php

declare(strict_types=1);

namespace App\Services\Malan\Exceptions;

use Exception;

class MalanApiException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?int $httpStatus = null,
        public readonly string $userMessage = 'صار خلل مؤقت وأنا بفحص الحساب. رح أسجل متابعة وبنرجع عليك.',
        ?Exception $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalidInput(string $message, string $userMessage): self
    {
        return new self($message, 'invalid_input', 400, $userMessage);
    }

    public static function unauthorized(): self
    {
        return new self(
            'Malan API authentication failed.',
            'unauthorized',
            401,
            'صار خلل مؤقت وأنا بفحص الحساب. رح أسجل متابعة وبنرجع عليك.',
        );
    }

    public static function notFound(): self
    {
        return new self(
            'Customer not found.',
            'not_found',
            404,
            'هالرقم شكله مش مسجّل عندنا بالنظام. تأكد من الرقم، أو ابعت رقم تلفون مسجّل تاني أو رقم الهوية.',
        );
    }

    public static function conflict(string $lookupType): self
    {
        // Phone collision: ask for identity once. Identity collision: escalate — never re-ask identity.
        $userMessage = $lookupType === 'phone'
            ? 'في أكثر من حساب على هالتلفون. ابعتلي رقم هوية صاحب الخط عشان أحدد الصح.'
            : 'في التباس بالحسابات بعد رقم الهوية. بحوّل لموظف يحدد الخط الصح ويتواصل معك. ما ترجع تبعت الهوية مرة تانية.';

        return new self('Multiple customers matched.', 'conflict', 409, $userMessage);
    }

    public static function rateLimited(): self
    {
        return new self(
            'Malan API rate limited.',
            'rate_limited',
            429,
            'ما قدرت أفحص الحساب هلق. رح أسجل متابعة وبنرجع عليك.',
        );
    }

    public static function methodNotAllowed(): self
    {
        return new self(
            'Malan API method not allowed.',
            'method_not_allowed',
            405,
            'صار خلل مؤقت وأنا بفحص الحساب. رح أسجل متابعة وبنرجع عليك.',
        );
    }

    public static function serverError(?int $status = 500): self
    {
        return new self(
            'Malan API server error.',
            'server_error',
            $status,
            'صار خلل مؤقت وأنا بفحص الحساب. رح أسجل متابعة وبنرجع عليك.',
        );
    }

    public static function timeout(): self
    {
        return new self(
            'Malan API timeout.',
            'timeout',
            null,
            'صار خلل مؤقت وأنا بفحص الحساب. رح أسجل متابعة وبنرجع عليك.',
        );
    }

    public static function unexpectedPayload(string $detail = ''): self
    {
        return new self(
            'Unexpected Malan API payload'.($detail !== '' ? ': '.$detail : ''),
            'unexpected_payload',
            200,
            'صار خلل مؤقت وأنا بفحص الحساب. رح أسجل متابعة وبنرجع عليك.',
        );
    }
}
