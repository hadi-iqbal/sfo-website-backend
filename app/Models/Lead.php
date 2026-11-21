<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Lead extends Model
{
    public const SALES_TYPES = ['contact', 'quote', 'rate', 'schedule', 'chat'];

    protected $fillable = [
        'type', 'status', 'name', 'email', 'phone', 'track', 'message', 'payload',
        'owner_id', 'follow_up_on', 'internal_notes',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query->whereIn('type', self::SALES_TYPES);
        if ($user->is_super_admin) {
            return $query;
        }

        $query->whereIn('type', array_filter(self::SALES_TYPES, fn ($type) => $user->hasPermission('leads.'.$type)));
        $tracks = $this->restrictedTracks($user);

        return $tracks ? $query->whereIn('track', $tracks) : $query;
    }

    public function restrictedTracks(User $user): array
    {
        return $user->is_super_admin ? [] : array_values(array_filter(['life', 'pnc'], fn ($track) => $user->hasPermission('leads.scope.'.$track)));
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
