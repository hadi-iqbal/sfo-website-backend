<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('conversations', fn (Blueprint $table) => $table->string('nirvana_language', 80)->nullable());
    }

    public function down(): void
    {
        Schema::table('conversations', fn (Blueprint $table) => $table->dropColumn('nirvana_language'));
    }
};
