<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DirectoryProfile;
use App\Models\Lead;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $user->loadMissing('permissions');
        $filters = $request->validate([
            'days' => ['nullable', 'integer', 'in:7,30,90'],
            'track' => ['nullable', 'in:life,pnc,unclassified'],
            'source' => ['nullable', 'in:website,nirvana'],
        ]);
        $days = (int) ($filters['days'] ?? 30);
        $start = today()->subDays($days - 1);
        $leads = Lead::visibleTo($user);
        if (! empty($filters['track'])) {
            $filters['track'] === 'unclassified' ? $leads->whereNull('track') : $leads->where('track', $filters['track']);
        }
        if (! empty($filters['source'])) {
            $leads->where('type', $filters['source'] === 'nirvana' ? '=' : '!=', 'chat');
        }
        $window = (clone $leads)->where('created_at', '>=', $start);
        $countsByDay = (clone $window)->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupByRaw('DATE(created_at)')->pluck('total', 'day');
        $tracks = array_filter(['agent', 'pnc'], fn ($track) => $user->hasPermission('directory.'.$track));
        $directory = DirectoryProfile::whereIn('track', $tracks);

        return response()->json([
            'user' => $user->toAdminArray(),
            'counts' => [
                'leads' => (clone $window)->count(),
                'new_leads' => (clone $leads)->where('status', 'new')->count(),
                'overdue' => (clone $leads)->where('follow_up_on', '<', today()->toDateString())->where('status', '!=', 'closed')->count(),
                'unassigned' => (clone $leads)->whereNull('owner_id')->where('status', '!=', 'closed')->count(),
                'directory_pending' => $directory->where('status', 'pending')->count(),
            ],
            'trend' => collect(range(0, $days - 1))->map(function ($offset) use ($start, $countsByDay) {
                $date = $start->copy()->addDays($offset)->toDateString();
                return ['date' => $date, 'count' => (int) ($countsByDay[$date] ?? 0)];
            }),
            'channels' => [
                'website' => (clone $window)->where('type', '!=', 'chat')->count(),
                'nirvana' => (clone $window)->where('type', 'chat')->count(),
            ],
            'stages' => $window->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function permissions()
    {
        return response()->json([
            'data' => Permission::orderBy('group')->orderBy('label')->get(),
        ]);
    }

    public function users()
    {
        $users = User::with('permissions')->orderBy('name')->get()
            ->map(fn (User $user) => $user->toAdminArray())
            ->values();

        return response()->json(['data' => $users]);
    }

    public function storeUser(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['sometimes', 'required', 'string', 'min:8', 'max:72'],
            'is_active' => ['boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,slug'],
        ]);
        $this->authorizePermissions($request->user(), $data['permissions'] ?? []);

        $temporaryPassword = $data['password'] ?? Str::password(20);
        $user = DB::transaction(function () use ($data, $temporaryPassword) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $temporaryPassword,
                'is_active' => $data['is_active'] ?? true,
                'is_super_admin' => false,
                'requires_profile_setup' => true,
            ]);
            $this->syncPermissions($user, $data['permissions'] ?? []);

            return $user->load('permissions');
        });

        return response()->json(['data' => $user->toAdminArray(), 'temporary_password' => $temporaryPassword], 201)->header('Cache-Control', 'no-store');
    }

    public function updateUser(Request $request, User $user)
    {
        if ($user->is_super_admin && $request->user()->id !== $user->id) {
            abort(403, 'The super admin account is managed separately.');
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:72'],
            'issue_temporary_password' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,slug'],
        ]);
        $this->authorizePermissions($request->user(), $user->permissionSlugs());
        if (array_key_exists('permissions', $data)) {
            $this->authorizePermissions($request->user(), $data['permissions']);
        }
        abort_if(($data['is_active'] ?? true) === false && $request->user()->id === $user->id, 422, 'You cannot disable your own account.');

        $temporaryPassword = ! empty($data['issue_temporary_password']) ? Str::password(20) : ($data['password'] ?? null);
        abort_if($temporaryPassword && $request->user()->id === $user->id, 422, 'Change your own password in Profile settings.');

        $updated = DB::transaction(function () use ($user, $data, $temporaryPassword) {
            $user = User::lockForUpdate()->findOrFail($user->id);
            if ($temporaryPassword) {
                $user->password = $temporaryPassword;
                $user->requires_profile_setup = true;
            }
            $user->fill(collect($data)->only(['name', 'email', 'is_active'])->all());
            $user->save();
            if (! $user->is_active || $temporaryPassword) {
                $user->tokens()->delete();
            }
            if (array_key_exists('permissions', $data) && ! $user->is_super_admin) {
                $this->syncPermissions($user, $data['permissions']);
            }

            return $user->load('permissions');
        });

        return response()->json(['data' => $updated->toAdminArray()] + ($temporaryPassword ? ['temporary_password' => $temporaryPassword] : []))
            ->header('Cache-Control', 'no-store');
    }

    private function syncPermissions(User $user, array $slugs): void
    {
        $ids = Permission::whereIn('slug', $slugs)->pluck('id');
        $user->permissions()->sync($ids);
    }

    private function authorizePermissions(User $actor, array $permissions): void
    {
        abort_unless($actor->is_super_admin || ! array_diff($permissions, $actor->permissionSlugs()), 403, 'You can only manage permissions you hold.');

        $scopes = ['leads.scope.life', 'leads.scope.pnc'];
        $leadAccess = array_map(fn ($type) => 'leads.'.$type, Lead::SALES_TYPES);
        $leadAccess[] = 'chat.view';
        if (! $actor->is_super_admin && array_intersect($scopes, $actor->permissionSlugs()) && array_intersect($leadAccess, $permissions)) {
            abort_unless(array_intersect($scopes, $permissions), 403, 'Lead access must stay within your permitted business lines.');
        }
    }
}
