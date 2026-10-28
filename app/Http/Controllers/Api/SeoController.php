<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SeoPage;
use Illuminate\Http\Request;

class SeoController extends Controller
{
    public function show(string $slug)
    {
        $page = SeoPage::with('faqs')->where('slug', $slug)->firstOrFail();

        return response()->json(['data' => $page->toPublicArray()]);
    }

    public function index()
    {
        return response()->json([
            'data' => SeoPage::with('faqs')->orderBy('label')->get()->map->toPublicArray(),
        ]);
    }

    public function update(Request $request, SeoPage $seoPage)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'meta_description' => ['nullable', 'string', 'max:400'],
            'og_title' => ['nullable', 'string', 'max:180'],
            'og_description' => ['nullable', 'string', 'max:400'],
            'canonical' => ['nullable', 'string', 'max:255'],
            'faqs' => ['array'],
            'faqs.*.id' => ['nullable', 'integer'],
            'faqs.*.question' => ['required', 'string', 'max:255'],
            'faqs.*.answer' => ['required', 'string', 'max:5000'],
        ]);

        $seoPage->update(collect($data)->except('faqs')->all());

        $kept = [];
        foreach ($data['faqs'] ?? [] as $index => $faq) {
            $record = isset($faq['id'])
                ? $seoPage->faqs()->whereKey($faq['id'])->first()
                : null;

            $payload = [
                'question' => $faq['question'],
                'answer' => $faq['answer'],
                'sort_order' => $index,
            ];

            if ($record) {
                $record->update($payload);
            } else {
                $record = $seoPage->faqs()->create($payload);
            }

            $kept[] = $record->id;
        }

        $seoPage->faqs()->whereNotIn('id', $kept)->delete();

        return response()->json(['data' => $seoPage->fresh('faqs')->toPublicArray()]);
    }
}
