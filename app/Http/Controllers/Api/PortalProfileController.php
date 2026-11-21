<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class PortalProfileController extends Controller
{
    public function profile(Request $request)
    {
        return $this->saveProfile($request, false);
    }

    public function setup(Request $request)
    {
        abort_unless($request->user()->is_active && $request->user()->requires_profile_setup
            && $request->user()->tokenCan('profile:setup'), 403, 'Profile setup is not available for this session.');

        return $this->saveProfile($request, true);
    }

    private function saveProfile(Request $request, bool $setup)
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'portal_theme' => ['required', 'in:light,dark'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
            'remove_avatar' => ['sometimes', 'boolean'],
        ];
        if ($setup) {
            $rules['password'] = ['required', 'string', 'min:12', 'max:72', 'confirmed'];
        }
        $data = $request->validate($rules);
        $disk = Storage::disk('local');
        $uploaded = $request->file('avatar')?->store('portal-avatars', 'local');
        abort_if($uploaded === false, 500, 'Could not store the profile picture.');

        try {
            [$user, $oldAvatar, $token] = DB::transaction(function () use ($request, $data, $setup, $uploaded) {
                $user = User::lockForUpdate()->findOrFail($request->user()->id);
                abort_unless($user->is_active && $user->requires_profile_setup === $setup, 403, 'Sign in again to continue.');
                $this->assertCurrentSession($request, $user);
                $oldAvatar = $user->avatar_path;
                $user->fill(['name' => $data['name'], 'portal_theme' => $data['portal_theme']]);
                if ($uploaded || ($data['remove_avatar'] ?? false)) {
                    $user->avatar_path = $uploaded;
                }
                if ($setup) {
                    $this->setPassword($user, $data['password']);
                    $user->requires_profile_setup = false;
                }
                $user->save();
                $token = $setup ? $this->renewSession($user) : null;

                return [$user->load('permissions'), $oldAvatar, $token];
            });
        } catch (\Throwable $error) {
            if ($uploaded) {
                $disk->delete($uploaded);
            }
            throw $error;
        }
        if ($oldAvatar && $oldAvatar !== $user->avatar_path) {
            $disk->delete($oldAvatar);
        }

        return response()->json(['user' => $user->toAdminArray()] + ($token ? ['token' => $token] : []))
            ->header('Cache-Control', 'no-store');
    }

    public function password(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'max:72', 'confirmed'],
        ]);

        $token = DB::transaction(function () use ($request, $data) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($user->is_active && ! $user->requires_profile_setup, 403, 'Sign in again to continue.');
            $this->assertCurrentSession($request, $user);
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
            }
            $this->setPassword($user, $data['password']);
            $user->save();

            return $this->renewSession($user);
        });

        return response()->json(['token' => $token])->header('Cache-Control', 'no-store');
    }

    public function avatar(Request $request)
    {
        $user = $request->user();
        abort_unless($user->is_active, 403);
        $disk = Storage::disk('local');
        abort_unless($user->avatar_path && $disk->exists($user->avatar_path), 404);

        return $disk->response($user->avatar_path, null, ['Cache-Control' => 'private, no-store']);
    }

    private function setPassword(User $user, string $password): void
    {
        if (strlen($password) > 72) {
            throw ValidationException::withMessages(['password' => 'Use a password of at most 72 bytes.']);
        }
        if (Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a password different from your current one.']);
        }
        $user->password = $password;
    }

    private function assertCurrentSession(Request $request, User $user): void
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            abort_unless($user->tokens()->whereKey($token->getKey())->exists(), 401, 'Sign in again to continue.');
        }
    }

    private function renewSession(User $user): string
    {
        $user->tokens()->delete();

        return $user->createToken('admin', ['portal'])->plainTextToken;
    }
}
