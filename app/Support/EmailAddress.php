<?php

namespace App\Support;

class EmailAddress
{
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function isDemo(string $email): bool
    {
        $email = self::normalize($email);

        return str_ends_with($email, '@demo.bookyourhotel.test')
            || $email === 'recruiter.supplier@example.test';
    }
}
