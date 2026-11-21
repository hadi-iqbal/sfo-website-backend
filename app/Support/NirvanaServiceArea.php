<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class NirvanaServiceArea
{
    // These are the jurisdictions published in the SFO footer. A state can span zones.
    public const ZONES = [
        'AK' => ['America/Anchorage', 'America/Adak'], 'AL' => ['America/Chicago'],
        'AR' => ['America/Chicago'], 'AZ' => ['America/Phoenix', 'America/Denver'],
        'CA' => ['America/Los_Angeles'], 'CO' => ['America/Denver'],
        'CT' => ['America/New_York'], 'DC' => ['America/New_York'],
        'DE' => ['America/New_York'], 'FL' => ['America/New_York', 'America/Chicago'],
        'GA' => ['America/New_York'], 'IA' => ['America/Chicago'],
        'ID' => ['America/Boise', 'America/Los_Angeles'], 'IN' => ['America/Indiana/Indianapolis', 'America/Chicago'],
        'KS' => ['America/Chicago', 'America/Denver'], 'KY' => ['America/New_York', 'America/Chicago'],
        'LA' => ['America/Chicago'], 'MA' => ['America/New_York'],
        'MD' => ['America/New_York'], 'ME' => ['America/New_York'],
        'MI' => ['America/Detroit', 'America/Chicago'], 'MO' => ['America/Chicago'],
        'MS' => ['America/Chicago'], 'NC' => ['America/New_York'],
        'ND' => ['America/Chicago', 'America/Denver'], 'NE' => ['America/Chicago', 'America/Denver'],
        'NJ' => ['America/New_York'], 'NM' => ['America/Denver'],
        'NY' => ['America/New_York'], 'OH' => ['America/New_York'],
        'OK' => ['America/Chicago'], 'OR' => ['America/Los_Angeles', 'America/Boise'],
        'PA' => ['America/New_York'], 'RI' => ['America/New_York'],
        'SC' => ['America/New_York'], 'TN' => ['America/Chicago', 'America/New_York'],
        'TX' => ['America/Chicago', 'America/Denver'], 'UT' => ['America/Denver'],
        'VA' => ['America/New_York'], 'WA' => ['America/Los_Angeles'],
        'WI' => ['America/Chicago'], 'WV' => ['America/New_York'],
        'WY' => ['America/Denver'],
    ];

    public static function validateLocation(string $state, string $zip, string $timezone): void
    {
        self::validateStateZip($state, $zip);
        if (!in_array($timezone, self::ZONES[$state], true)) {
            throw ValidationException::withMessages(['timezone' => 'Choose a time zone used in the selected state.']);
        }
    }

    public static function validateStateZip(string $state, string $zip): void
    {
        if (!array_key_exists($state, self::ZONES)) {
            throw ValidationException::withMessages(['state' => 'Choose a state served by SFO.']);
        }
        if (!preg_match('/^\d{5}$/D', $zip)) {
            throw ValidationException::withMessages(['zip' => 'Enter a five-digit ZIP code.']);
        }
    }
}
