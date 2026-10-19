<?php

namespace App\Support\Settings;

use App\Models\Setting;

class AdSettings
{
    public const KEY = 'adsense';

    public const PLACEMENTS = ['sidebar', 'in_article', 'anchor'];

    /** @return array{enabled: bool, client: ?string, placements: array<string, array{enabled: bool, slot: ?string}>} */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'client' => null,
            'placements' => array_fill_keys(self::PLACEMENTS, ['enabled' => false, 'slot' => null]),
        ];
    }

    /** @return array{enabled: bool, client: ?string, placements: array<string, array{enabled: bool, slot: ?string}>} */
    public static function get(): array
    {
        $stored = Setting::get(self::KEY, []);

        return self::normalize(is_array($stored) ? $stored : []);
    }

    /** @param  array<string, mixed>  $input */
    public static function save(array $input): void
    {
        Setting::put(self::KEY, self::normalize($input));
    }

    /**
     * Keeps only known keys and coerces types. Values that fail their format
     * check become null, and ads can never be on without the IDs they need.
     *
     * @param  array<string, mixed>  $input
     * @return array{enabled: bool, client: ?string, placements: array<string, array{enabled: bool, slot: ?string}>}
     */
    public static function normalize(array $input): array
    {
        $result = self::defaults();

        $client = $input['client'] ?? null;
        $result['client'] = is_string($client) && preg_match('/^ca-pub-\d{16}$/', trim($client)) ? trim($client) : null;
        $result['enabled'] = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN) && $result['client'] !== null;

        foreach (self::PLACEMENTS as $placement) {
            $item = is_array($input['placements'][$placement] ?? null) ? $input['placements'][$placement] : [];
            $slot = $item['slot'] ?? null;
            $slot = (is_string($slot) || is_int($slot)) && preg_match('/^\d{6,20}$/', (string) $slot) ? (string) $slot : null;

            $result['placements'][$placement] = [
                'enabled' => filter_var($item['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN) && $slot !== null,
                'slot' => $slot,
            ];
        }

        return $result;
    }
}