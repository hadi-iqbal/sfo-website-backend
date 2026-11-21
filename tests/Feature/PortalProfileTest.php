<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortalProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_temporary_password_requires_setup_and_cannot_be_reused(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $adminToken = $admin->createToken('admin', ['portal'])->plainTextToken;
        $created = $this->asToken($adminToken)->postJson('/api/admin/users', [
            'name' => 'New colleague', 'email' => 'new@example.test',
        ])->assertCreated()->assertJsonPath('data.requires_profile_setup', true);
        $temporary = $created->json('temporary_password');
        $this->assertGreaterThanOrEqual(16, strlen($temporary));
        $this->asToken($adminToken)->getJson('/api/admin/users')->assertOk()->assertJsonMissingPath('data.0.temporary_password');

        $setupToken = $this->signIn('new@example.test', $temporary)
            ->assertOk()->assertJsonPath('user.requires_profile_setup', true)->json('token');
        $this->asToken($setupToken)->getJson('/api/admin/dashboard')->assertForbidden();
        $this->asToken($setupToken)->getJson('/api/admin/company')->assertForbidden();
        $this->asToken($setupToken)->postJson('/api/admin/me/profile', ['name' => 'Bypass', 'portal_theme' => 'light'])->assertForbidden();
        $this->asToken($setupToken)->postJson('/api/admin/me/setup', [
            'name' => 'New colleague', 'portal_theme' => 'dark',
            'password' => $temporary, 'password_confirmation' => $temporary,
        ])->assertUnprocessable();

        $permanent = 'permanent-test-password';
        $token = $this->asToken($setupToken)->postJson('/api/admin/me/setup', [
            'name' => 'Updated colleague', 'portal_theme' => 'dark',
            'password' => $permanent, 'password_confirmation' => $permanent,
        ])->assertOk()->assertJsonPath('user.requires_profile_setup', false)->json('token');
        $this->asToken($setupToken)->getJson('/api/admin/me')->assertUnauthorized();
        $this->signIn('new@example.test', $temporary)->assertUnprocessable();
        $this->asToken($token)->getJson('/api/admin/me')->assertOk()
            ->assertJsonPath('user.name', 'Updated colleague')->assertJsonPath('user.portal_theme', 'dark');

        $reset = $this->asToken($adminToken)->patchJson('/api/admin/users/'.$created->json('data.id'), [
            'issue_temporary_password' => true,
        ])->assertOk()->assertJsonPath('data.requires_profile_setup', true);
        $this->asToken($token)->getJson('/api/admin/me')->assertUnauthorized();
        $this->signIn('new@example.test', $reset->json('temporary_password'))
            ->assertOk()->assertJsonPath('user.requires_profile_setup', true);
    }

    public function test_private_avatar_and_password_change_are_scoped_to_the_signed_in_user(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['password' => 'initial-test-password', 'is_active' => true]);
        $token = $user->createToken('admin', ['portal'])->plainTextToken;
        $other = $user->createToken('other device', ['portal'])->plainTextToken;
        $this->asToken($token)->post('/api/admin/me/profile', [
            'name' => 'Updated name', 'portal_theme' => 'dark',
            'avatar' => UploadedFile::fake()->createWithContent('avatar.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1sAAAAASUVORK5CYII=')),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('user.portal_theme', 'dark');
        $avatarPath = $user->fresh()->avatar_path;
        Storage::disk('local')->assertExists($avatarPath);
        $this->asToken($token)->get('/api/admin/me/avatar')->assertOk();
        $unrelated = User::factory()->create(['is_active' => true])->createToken('admin', ['portal'])->plainTextToken;
        $this->asToken($unrelated)->getJson('/api/admin/me/avatar')->assertNotFound();

        $new = ['password' => 'changed-test-password', 'password_confirmation' => 'changed-test-password'];
        $this->asToken($token)->patchJson('/api/admin/me/password', ['current_password' => 'wrong'] + $new)->assertUnprocessable();
        $replacement = $this->asToken($token)->patchJson('/api/admin/me/password', ['current_password' => 'initial-test-password'] + $new)->assertOk()->json('token');
        $this->asToken($other)->getJson('/api/admin/me')->assertUnauthorized();
        $this->asToken($token)->getJson('/api/admin/me')->assertUnauthorized();
        $this->asToken($replacement)->postJson('/api/admin/me/profile', ['name' => 'Updated name', 'portal_theme' => 'light', 'remove_avatar' => true])->assertOk()
            ->assertJsonPath('user.avatar_version', null);
        Storage::disk('local')->assertMissing($avatarPath);
    }

    private function asToken(string $token): static
    {
        app('auth')->forgetGuards();
        return $this->withToken($token);
    }

    private function signIn(string $email, string $password)
    {
        app('auth')->forgetGuards();
        return $this->withoutHeader('Authorization')->postJson('/api/admin/login', compact('email', 'password'));
    }
}
