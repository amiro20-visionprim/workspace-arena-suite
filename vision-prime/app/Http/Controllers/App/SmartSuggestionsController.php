<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domains\Content\Services\SmartTagger;
use App\Domains\Content\Services\CategoryMatcher;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SmartSuggestionsController extends Controller
{
    /**
     * پیشنهاد هوشمند تگ و دسته بر اساس عنوان مقاله
     */
    public function suggest(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:500',
            'site_id' => 'required|integer|exists:sites,id',
            'keyword' => 'nullable|string|max:500',
            'content' => 'nullable|string|max:10000',
        ]);

        $title = $request->input('title');
        $keyword = $request->input('keyword', '');
        $content = $request->input('content', '');
        $siteId = $request->input('site_id');

        // دریافت دسته‌ها و تگ‌های وردپرس
        $categories = [];
        $tags = [];
        $wpTerms = $this->getWpTerms($siteId);

        if ($wpTerms['success']) {
            $categories = $wpTerms['categories'] ?? [];
            $tags = $wpTerms['tags'] ?? [];
        }

        // پیشنهاد تگ هوشمند
        $suggestedTags = SmartTagger::suggest($title, $content, $keyword, $tags);

        // پیشنهاد دسته هوشمند
        $categoryMatcher = new CategoryMatcher();
        $suggestedCategories = $categoryMatcher->suggest($categories, $title, $keyword);

        return response()->json([
            'success' => true,
            'tags' => $suggestedTags,
            'categories' => $suggestedCategories['suggestions'] ?? [],
            'best_category_id' => $suggestedCategories['best_id'] ?? null,
            'new_category_suggested' => $suggestedCategories['new_category_suggested'] ?? false,
            'new_category_name' => $suggestedCategories['new_category_name'] ?? null,
            'note' => $suggestedCategories['note'] ?? null,
        ]);
    }

    /**
     * دریافت دسته‌ها و تگ‌های وردپرس
     */
    private function getWpTerms(int $siteId): array
    {
        try {
            $site = \App\Models\Site::findOrFail($siteId);
            $baseUrl = rtrim($site->canonical_url, '/');
            $username = $site->wp_username;
            $appPassword = $site->wp_app_password;

            if (!$username || !$appPassword) {
                return ['success' => false, 'error' => 'WordPress credentials not configured'];
            }

            $auth = base64_encode($username . ':' . $appPassword);

            // دریافت دسته‌ها
            $catResponse = Http::withHeaders([
                'Authorization' => 'Basic ' . $auth,
                'Accept' => 'application/json',
            ])->get($baseUrl . '/wp-json/wp/v2/categories', [
                'per_page' => 100,
                '_fields' => 'id,name,slug,count',
            ]);

            $categories = [];
            if ($catResponse->successful()) {
                foreach ($catResponse->json() as $cat) {
                    $categories[] = [
                        'id' => $cat['id'],
                        'name' => $cat['name'],
                        'slug' => $cat['slug'],
                        'post_count' => $cat['count'] ?? 0,
                    ];
                }
            }

            // دریافت تگ‌ها
            $tagResponse = Http::withHeaders([
                'Authorization' => 'Basic ' . $auth,
                'Accept' => 'application/json',
            ])->get($baseUrl . '/wp-json/wp/v2/tags', [
                'per_page' => 100,
                '_fields' => 'id,name,slug,count',
            ]);

            $tags = [];
            if ($tagResponse->successful()) {
                foreach ($tagResponse->json() as $tag) {
                    $tags[] = [
                        'id' => $tag['id'],
                        'name' => $tag['name'],
                        'slug' => $tag['slug'],
                        'count' => $tag['count'] ?? 0,
                    ];
                }
            }

            return [
                'success' => true,
                'categories' => $categories,
                'tags' => $tags,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
