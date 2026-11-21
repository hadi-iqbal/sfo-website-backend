<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('follow_up_on')->nullable();
            $table->text('internal_notes')->nullable();
            $table->index(['track', 'created_at']);
        });

        DB::table('leads')->whereNull('track')->whereIn('type', ['quote', 'rate'])->update(['track' => 'life']);

        foreach ($this->permissions() as $slug => $label) {
            DB::table('permissions')->insertOrIgnore([
                'slug' => $slug, 'label' => $label, 'group' => 'Workspace',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('slug', array_keys($this->permissions()))->delete();
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
            $table->dropIndex(['track', 'created_at']);
            $table->dropColumn(['follow_up_on', 'internal_notes']);
        });
    }

    private function permissions(): array
    {
        return [
            'leads.scope.life' => 'Limit lead access to Life Insurance',
            'leads.scope.pnc' => 'Limit lead access to Property & Casualty',
            'company.manage' => 'Manage Company content',
            'careers.manage' => 'Manage Careers and review applications',
            'explorer.manage' => 'Manage Site Explorer',
        ];
    }
};
