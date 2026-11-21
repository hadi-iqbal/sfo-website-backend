<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('portal_theme', 5)->default('light');
            $table->string('avatar_path')->nullable();
            $table->boolean('requires_profile_setup')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['portal_theme', 'avatar_path', 'requires_profile_setup']);
        });
    }
};
