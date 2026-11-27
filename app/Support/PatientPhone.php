<?php

namespace App\Support;

use Illuminate\Support\Str;

class PatientPhone
{
    // Bare numbers retain the original Hong Kong API contract.
    public const PATTERN = '/^(?:[2-9][0-9]{7}|\+852[2-9][0-9]{7}|\+861[3-9][0-9]{9})$/D';

    public static function international(string $phone): string
    {
        return str_starts_with($phone, '+') ? $phone : '+852'.$phone;
    }

    public static function identity(string $phone): string
    {
        $phone = self::international($phone);
        // Preserve existing HK hashes so upgrading does not split patient history.
        return str_starts_with($phone, '+852') ? substr($phone, 4) : $phone;
    }

    /** The patient identity key: the squished, lower-cased name and the phone identity. */
    public static function patientKey(string $name, string $phone): string
    {
        return hash_hmac('sha256', Str::lower(Str::squish($name)).'|'.self::identity($phone), config('app.key'));
    }
}
