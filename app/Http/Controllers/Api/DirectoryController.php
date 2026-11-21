<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DirectoryProfile;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DirectoryController extends Controller
{
    public function index(Request $request)
    {
        $track = $request->string('track', 'agent')->toString();
        abort_unless(in_array($track, ['agent', 'pnc'], true), 422);

        $profiles = DirectoryProfile::query()
            ->where('track', $track)
            ->where('status', 'approved')
            ->latest('reviewed_at')
            ->get()
            ->map->toPublicArray();

        return response()->json(['data' => $profiles]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'track' => ['required', 'in:agent,pnc'],
            'name' => ['required', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:120'],
            'license_number' => ['required', 'string', 'max:80'],
            'npn' => ['nullable', 'string', 'max:40'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:8'],
            'states_licensed' => ['nullable', 'string', 'max:500'],
            'specialties' => ['nullable', 'string', 'max:500'],
            'bio' => ['required', 'string', 'max:2000'],
            'photo' => ['required', 'image', 'max:4096'],
        ]);

        $path = $request->file('photo')->store('directory', 'public');

        $profile = DirectoryProfile::create([
            ...collect($data)->except(['photo', 'states_licensed', 'specialties'])->all(),
            'states_licensed' => $this->splitList($data['states_licensed'] ?? ''),
            'specialties' => $this->splitList($data['specialties'] ?? ''),
            'photo_path' => $path,
            'status' => 'pending',
        ]);

        Lead::create([
            'type' => 'directory',
            'track' => $profile->track,
            'name' => $profile->name,
            'email' => $profile->email,
            'phone' => $profile->phone,
            'message' => $profile->bio,
            'payload' => ['directory_profile_id' => $profile->id],
            'status' => 'new',
        ]);

        return response()->json(['id' => $profile->id, 'status' => 'pending'], 201);
    }

    public function adminIndex(Request $request)
    {
        $user = $request->user();
        $user->loadMissing('permissions');
        $tracks = $this->allowedTracks($user);

        $query = DirectoryProfile::query()->latest();
        if (! $user->is_super_admin) {
            $query->whereIn('track', $tracks);
        }
        if ($request->filled('track')) {
            $query->where('track', $request->string('track'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json([
            'data' => $query->get()->map->toAdminArray()->values(),
        ]);
    }

    public function update(Request $request, DirectoryProfile $profile)
    {
        $user = $request->user();
        $user->loadMissing('permissions');
        $this->assertTrack($user, $profile->track);

        $data = $request->validate([
            'status' => ['sometimes', 'in:pending,approved,rejected,changes_requested'],
            'review_note' => ['required_if:status,changes_requested', 'nullable', 'string', 'max:2000'],
            'name' => ['sometimes', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:120'],
            'license_number' => ['sometimes', 'string', 'max:80'],
            'npn' => ['nullable', 'string', 'max:40'],
            'phone' => ['sometimes', 'string', 'max:40'],
            'email' => ['sometimes', 'email'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:8'],
            'bio' => ['sometimes', 'string', 'max:2000'],
        ]);

        if (($request->has('status') || $request->has('review_note')) && ! $user->hasPermission('directory.review')) {
            abort(403, 'You can view this directory, but you cannot approve or reject applications.');
        }

        if (isset($data['status'])) {
            $data['reviewed_by'] = $user->id;
            $data['reviewed_at'] = now();
        }

        $profile->update($data);

        return response()->json(['data' => $profile->fresh()->toAdminArray()]);
    }

    public function destroy(Request $request, DirectoryProfile $profile)
    {
        $user = $request->user();
        $user->loadMissing('permissions');
        $this->assertTrack($user, $profile->track);

        if (! $user->hasPermission('directory.review')) {
            abort(403);
        }

        if ($profile->photo_path) {
            Storage::disk('public')->delete($profile->photo_path);
        }

        $profile->delete();

        return response()->json(['ok' => true]);
    }

    private function splitList(string $value): array
    {
        return collect(preg_split('/[,|]/', $value) ?: [])
            ->map(fn ($item) => trim($item))
            ->filter()
            ->values()
            ->all();
    }

    private function allowedTracks($user): array
    {
        return collect(['agent', 'pnc'])
            ->filter(fn (string $track) => $user->hasPermission('directory.'.$track))
            ->values()
            ->all();
    }

    private function assertTrack($user, string $track): void
    {
        if (! $user->hasPermission('directory.'.$track)) {
            abort(403);
        }
    }
}
