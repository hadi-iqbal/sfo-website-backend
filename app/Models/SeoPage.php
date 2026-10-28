<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoPage extends Model
{
    protected $fillable = [
        'slug', 'path', 'label', 'title', 'meta_description', 'og_title', 'og_description', 'canonical',
    ];

    public function faqs(): HasMany
    {
        return $this->hasMany(Faq::class)->orderBy('sort_order');
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'slug' => $this->slug,
            'path' => $this->path,
            'title' => $this->title,
            'meta_description' => $this->meta_description,
            'og_title' => $this->og_title ?: $this->title,
            'og_description' => $this->og_description ?: $this->meta_description,
            'canonical' => $this->canonical,
            'faqs' => $this->faqs->map(fn (Faq $faq) => [
                'id' => $faq->id,
                'question' => $faq->question,
                'answer' => $faq->answer,
                'sort_order' => $faq->sort_order,
            ])->values(),
        ];
    }
}
