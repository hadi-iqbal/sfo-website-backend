<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public function store(Request $request, string $type)
    {
        $rules = match ($type) {
            'contact' => [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email'],
                'phone' => ['required', 'string', 'max:40'],
                'message' => ['required', 'string', 'max:5000'],
            ],
            'quote' => [
                'first_name' => ['required', 'string', 'max:80'],
                'last_name' => ['required', 'string', 'max:80'],
                'cellphone' => ['required', 'string', 'max:40'],
                'landline' => ['nullable', 'string', 'max:40'],
                'state' => ['required', 'string', 'max:40'],
                'gender' => ['required', 'in:Male,Female'],
                'dob' => ['required', 'date'],
                'height_ft' => ['required', 'integer', 'between:4,7'],
                'height_in' => ['required', 'integer', 'between:0,11'],
                'weight' => ['required', 'numeric', 'min:50', 'max:700'],
                'face_amount' => ['required', 'string', 'max:40'],
                'term_length' => ['required', 'string', 'max:40'],
                'health_class' => ['required', 'string', 'max:40'],
                'email' => ['required', 'email'],
                'confirm_email' => ['required', 'same:email'],
                'tobacco' => ['required', 'in:Yes,No'],
                'exam' => ['required', 'in:Medical,Non-Medical'],
                'disability' => ['required', 'in:Yes,No'],
                'conditions' => ['nullable', 'array'],
                'conditions.*' => ['string', 'max:80'],
            ],
            'rate' => [
                'phone' => ['required', 'string', 'max:40'],
                'email' => ['required', 'email'],
                'state' => ['required', 'string', 'max:40'],
                'gender' => ['required', 'in:Male,Female'],
                'dob' => ['required', 'date'],
                'coverage' => ['required', 'numeric', 'min:1'],
                'tobacco' => ['required', 'in:Yes,No'],
            ],
            'schedule' => [
                'name' => ['required', 'string', 'max:120'],
                'cellphone' => ['required', 'string', 'max:40'],
                'landline' => ['nullable', 'string', 'max:40'],
                'email' => ['required', 'email'],
                'confirm_email' => ['required', 'same:email'],
                'date' => ['required', 'date'],
                'time' => ['required', 'string', 'max:20'],
                'timezone' => ['sometimes', 'in:America/Los_Angeles'],
            ],
            default => abort(404),
        };

        $lifeForm = in_array($type, ['quote', 'rate'], true);
        if (! $lifeForm) {
            $rules['track'] = ['nullable', 'in:life,pnc,general'];
        }
        $data = $request->validate($rules);

        $lead = Lead::create([
            'type' => $type,
            'track' => $lifeForm ? 'life' : (($data['track'] ?? null) === 'general' ? null : ($data['track'] ?? null)),
            'name' => $data['name'] ?? trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')),
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? $data['cellphone'] ?? null,
            'message' => $data['message'] ?? null,
            'payload' => $data,
        ]);

        return response()->json(['id' => $lead->id], 201);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $user->loadMissing('permissions');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(Lead::SALES_TYPES)],
            'request' => ['nullable', 'in:contact,quote,rate,schedule,chat'],
            'status' => ['nullable', 'in:new,contacted,closed'],
            'track' => ['nullable', 'in:life,pnc,unclassified'],
            'source' => ['nullable', 'in:website,nirvana'],
            'queue' => ['nullable', 'in:mine,unassigned,overdue'],
            'days' => ['nullable', 'integer', 'in:7,30,90'],
        ]);
        $query = Lead::visibleTo($user)->with('owner:id,name')->latest()->orderByDesc('id');
        foreach (['type', 'status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['track'])) {
            $filters['track'] === 'unclassified' ? $query->whereNull('track') : $query->where('track', $filters['track']);
        }
        if (! empty($filters['source'])) {
            $query->where('type', $filters['source'] === 'nirvana' ? '=' : '!=', 'chat');
        }
        if (! empty($filters['request'])) {
            $kind = $filters['request'];
            $query->where(function ($rows) use ($kind) {
                if ($kind === 'chat') {
                    $rows->where('type', 'chat')->whereNull('payload->request_kind');
                } else {
                    $rows->where('type', $kind)->orWhere(fn ($chat) => $chat->where('type', 'chat')->where('payload->request_kind', $kind));
                }
            });
        }
        if (! empty($filters['days'])) {
            $query->where('created_at', '>=', today()->subDays($filters['days'] - 1));
        }
        match ($filters['queue'] ?? null) {
            'mine' => $query->where('owner_id', $user->id),
            'unassigned' => $query->whereNull('owner_id')->where('status', '!=', 'closed'),
            'overdue' => $query->where('follow_up_on', '<', today()->toDateString())->where('status', '!=', 'closed'),
            default => null,
        };
        if (! empty($filters['q'])) {
            $term = '%'.$filters['q'].'%';
            $query->where(fn ($rows) => $rows->where('name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('phone', 'like', $term));
        }
        return response()->json(['data' => $query->paginate(25)]);
    }

    public function update(Request $request, Lead $lead)
    {
        $user = $request->user();
        $user->loadMissing('permissions');

        $data = $request->validate([
            'status' => ['sometimes', 'in:new,contacted,closed'],
            'track' => ['sometimes', 'nullable', 'in:life,pnc'],
            'owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'follow_up_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'internal_notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]);

        $updated = DB::transaction(function () use ($user, $lead, $data) {
            $current = Lead::visibleTo($user)->whereKey($lead->id)->lockForUpdate()->first();
            abort_unless($current, 403);
            return $this->saveLead($current, $user, $data);
        });

        return response()->json(['data' => $updated]);
    }

    private function saveLead(Lead $lead, User $user, array $data): Lead
    {
        $tracks = $lead->restrictedTracks($user);
        abort_if($tracks && array_key_exists('track', $data) && ! in_array($data['track'], $tracks, true), 403);

        $ownerId = array_key_exists('owner_id', $data) ? $data['owner_id'] : $lead->owner_id;
        if (array_key_exists('owner_id', $data) && ! $user->hasPermission('users.manage')) {
            abort_unless(($ownerId === null || (int) $ownerId === $user->id)
                && ($lead->owner_id === null || $lead->owner_id === $user->id), 403);
        }
        if ($ownerId && (array_key_exists('owner_id', $data) || array_key_exists('track', $data))) {
            $owner = User::with('permissions')->findOrFail($ownerId);
            $nextTrack = array_key_exists('track', $data) ? $data['track'] : $lead->track;
            $ownerTracks = $lead->restrictedTracks($owner);
            abort_unless($owner->is_active && $owner->hasPermission('leads.'.$lead->type)
                && (! $ownerTracks || in_array($nextTrack, $ownerTracks, true)), 422, 'Choose an active owner who can access this lead.');
        }
        $lead->update($data);
        return $lead->load('owner:id,name');
    }
}
