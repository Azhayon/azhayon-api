<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\NoParametersRequest;
use App\Models\Category;
use App\Models\Setting;
use App\Support\Cache\ContentVersion;
use App\Support\Settings\AdSettings;
use Illuminate\Http\JsonResponse;

class SiteController extends Controller
{
    private const DEFAULT_NAME = 'Azhayon Media';

    private const DEFAULT_TAGLINE = 'Movies, anime, manhwa and manga: news, reviews and guides.';

    public function __invoke(NoParametersRequest $request): JsonResponse
    {
        $data = ContentVersion::remember('site', 300, function (): array {
            $site = Setting::get('site', []);
            $site = is_array($site) ? $site : [];

            return [
                'name' => $this->text($site, 'name', self::DEFAULT_NAME),
                'tagline' => $this->text($site, 'tagline', self::DEFAULT_TAGLINE),
                'url' => rtrim((string) config('azhayon.frontend_url'), '/'),
                'ads' => $this->ads(),
                'categories' => Category::query()
                    ->orderBy('name')
                    ->get(['name', 'slug'])
                    ->map(fn (Category $category): array => ['name' => $category->name, 'slug' => $category->slug])
                    ->all(),
            ];
        });

        return response()->json(['data' => $data]);
    }

    /** @param  array<string, mixed>  $site */
    private function text(array $site, string $key, string $default): string
    {
        $value = $site[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /**
     * Only public-safe values. While ads are off, nothing about the account is published.
     *
     * @return array<string, mixed>
     */
    private function ads(): array
    {
        $ads = AdSettings::get();

        if (! $ads['enabled'] || $ads['client'] === null) {
            return ['enabled' => false];
        }

        return [
            'enabled' => true,
            'client' => $ads['client'],
            'placements' => array_map(fn (array $placement): array => [
                'enabled' => $placement['enabled'],
                'slot' => $placement['enabled'] ? $placement['slot'] : null,
            ], $ads['placements']),
            'rules' => ['in_article_every' => 4, 'in_article_max' => 3],
        ];
    }
}