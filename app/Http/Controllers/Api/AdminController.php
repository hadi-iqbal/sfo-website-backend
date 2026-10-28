<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\DirectoryProfile;
use App\Models\Lead;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $user->loadMissing('permissions');

        return response()->json([
            'user' => $user->toAdminArray(),
            'counts' => [
                'leads' => Lead::count(),
                'new_leads' => Lead::where('status', 'new')->count(),
                'directory_pending' => DirectoryProfile::where('status', 'pending')->count(),
                'directory_live' => DirectoryProfile::where('status', 'approved')->count(),
                'conversations' => Conversation::count(),
            ],
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
            'password' => ['required', 'string', 'min:8'],
            'is_active' => ['boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,slug'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'is_active' => $data['is_active'] ?? true,
            'is_super_admin' => false,
        ]);

        $this->syncPermissions($user, $data['permissions'] ?? []);

        return response()->json(['data' => $user->fresh('permissions')->toAdminArray()], 201);
    }

    public function updateUser(Request $request, User $user)
    {
        if ($user->is_super_admin && $request->user()->id !== $user->id) {
            abort(403, 'The super admin account is managed separately.');
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'is_active' => ['sometimes', 'boolean'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,slug'],
        ]);

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->fill(collect($data)->only(['name', 'email', 'is_active'])->all());
        $user->save();

        if (array_key_exists('permissions', $data) && ! $user->is_super_admin) {
            $this->syncPermissions($user, $data['permissions']);
        }

        return response()->json(['data' => $user->fresh('permissions')->toAdminArray()]);
    }

    private function syncPermissions(User $user, array $slugs): void
    {
        $ids = Permission::whereIn('slug', $slugs)->pluck('id');
        $user->permissions()->sync($ids);
    }
}
