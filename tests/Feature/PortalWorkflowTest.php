<?php

namespace Tests\Feature;

use App\Models\CompanyRecord;
use App\Models\Lead;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_intake_sources_and_sub_account_scope_match_inbox_and_dashboard(): void
    {
        $contact = ['name' => 'Test Visitor', 'email' => 'visitor@example.test', 'phone' => '555-0100', 'message' => 'A test inquiry'];
        $pnc = $this->postJson('/api/leads/contact', $contact + ['track' => 'pnc'])->assertCreated()->json('id');
        $life = $this->postJson('/api/leads/contact', $contact + ['track' => 'life'])->assertCreated()->json('id');
        $this->postJson('/api/leads/contact', $contact + ['track' => 'general'])->assertCreated();
        $chat = $this->postJson('/api/chat', $contact + ['track' => 'pnc', 'request_kind' => 'quote'])->assertCreated()->json('conversation_id');
        $otherChat = $this->postJson('/api/chat', $contact + ['track' => 'life'])->assertCreated()->json('conversation_id');
        Lead::create(['type' => 'directory', 'track' => 'pnc', 'payload' => []]);

        $reviewer = $this->account(['dashboard.view', 'leads.contact', 'leads.chat', 'leads.scope.pnc', 'chat.view']);
        Sanctum::actingAs($reviewer, ['portal']);

        $this->getJson('/api/admin/leads?source=website')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $pnc);
        $this->getJson('/api/admin/leads?source=nirvana')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.track', 'pnc');
        $this->getJson('/api/admin/leads?request=quote')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.type', 'chat');
        $this->getJson('/api/admin/dashboard?days=7')->assertOk()->assertJsonPath('counts.leads', 2)
            ->assertJsonPath('channels.nirvana', 1)->assertJsonPath('channels.website', 1)->assertJsonCount(7, 'trend');
        $this->getJson('/api/admin/chat')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $chat);
        $this->getJson('/api/admin/chat/'.$otherChat)->assertForbidden();
        $this->patchJson('/api/admin/leads/'.$life, ['status' => 'contacted'])->assertForbidden();
        $this->patchJson('/api/admin/leads/'.$pnc, ['track' => 'life'])->assertForbidden();
        $this->patchJson('/api/admin/leads/'.$pnc, ['owner_id' => $reviewer->id, 'follow_up_on' => '2026-12-01', 'status' => 'contacted'])
            ->assertOk()->assertJsonPath('data.owner.id', $reviewer->id)->assertJsonPath('data.status', 'contacted');
        $this->getJson('/api/admin/leads?queue=mine')->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_company_access_does_not_include_careers_applications_or_account_management(): void
    {
        CompanyRecord::create(['kind' => 'job', 'data' => ['name' => 'Private opening', 'published' => false]]);
        Sanctum::actingAs($this->account(['company.manage']), ['portal']);
        $this->getJson('/api/admin/company')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/admin/company', ['kind' => 'job', 'data' => ['name' => 'Forbidden', 'published' => false]])->assertForbidden();
        $this->getJson('/api/admin/applications')->assertForbidden();
        $this->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_scoped_account_manager_cannot_delegate_unrestricted_lead_access(): void
    {
        Sanctum::actingAs($this->account(['users.manage', 'leads.contact', 'leads.scope.pnc']), ['portal']);
        $account = ['name' => 'Sales staff', 'email' => 'staff@example.test', 'password' => 'local-test-password'];

        $this->postJson('/api/admin/users', $account + ['permissions' => ['leads.contact']])->assertForbidden();
        $this->postJson('/api/admin/users', $account + ['permissions' => ['leads.contact', 'leads.scope.pnc']])->assertCreated();
    }

    private function account(array $slugs): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($slugs as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['label' => $slug, 'group' => 'Test']);
            $user->permissions()->attach($permission->id);
        }
        return $user;
    }
}
