<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Faq extends Model
{
    protected $fillable = ['seo_page_id', 'question', 'answer', 'sort_order'];

    public function page(): BelongsTo
    {
        return $this->belongsTo(SeoPage::class, 'seo_page_id');
    }
}
