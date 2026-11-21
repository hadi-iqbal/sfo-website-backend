<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('nirvana_meetings', function (Blueprint $table) {
            $table->string('visitor_state', 2)->nullable();
            $table->string('visitor_zip', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nirvana_meetings', fn (Blueprint $table) => $table->dropColumn(['visitor_state', 'visitor_zip']));
    }
};
