<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DirectoryProfile extends Model
{
    protected $fillable = [
        'track', 'status', 'name', 'title', 'license_number', 'npn', 'phone', 'email',
        'city', 'state', 'states_licensed', 'specialties', 'bio', 'photo_path',
        'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'states_licensed' => 'array',
            'specialties' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? asset('storage/'.$this->photo_path) : null;
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'track' => $this->track,
            'name' => $this->name,
            'title' => $this->title,
            'license_number' => $this->license_number,
            'npn' => $this->npn,
            'phone' => $this->phone,
            'email' => $this->email,
            'city' => $this->city,
            'state' => $this->state,
            'states_licensed' => $this->states_licensed ?? [],
            'specialties' => $this->specialties ?? [],
            'bio' => $this->bio,
            'photo_url' => $this->photoUrl(),
        ];
    }

    public function toAdminArray(): array
    {
        return $this->toPublicArray() + [
            'status' => $this->status,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at,
            'created_at' => $this->created_at,
        ];
    }
}
