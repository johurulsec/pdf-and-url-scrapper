<?php

declare(strict_types=1);

namespace App;

final class Geocode
{
    public static function address(string $address, Config $config): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        try {
            if ($config->googleMapsApiKey) {
                $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
                    'address' => $address,
                    'language' => 'ja',
                    'region' => 'jp',
                    'key' => $config->googleMapsApiKey,
                ]);
                $data = json_decode((string)Http::request('GET', $url, ['timeout' => 12]), true);
                if (!empty($data['results'][0]['geometry']['location'])) {
                    $loc = $data['results'][0]['geometry']['location'];
                    return [(float)$loc['lat'], (float)$loc['lng']];
                }
            }

            $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
                'q' => $address,
                'format' => 'json',
                'limit' => 1,
            ]);
            $body = Http::request('GET', $url, [
                'headers' => ['User-Agent: jp-pdf-json-php/1.0'],
                'timeout' => 12,
            ]);
            $arr = json_decode((string)$body, true);
            if (!empty($arr[0]['lat']) && !empty($arr[0]['lon'])) {
                return [(float)$arr[0]['lat'], (float)$arr[0]['lon']];
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
