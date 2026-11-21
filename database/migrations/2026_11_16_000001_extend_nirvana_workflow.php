<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('nirvana_end_requested_at')->nullable();
            $table->timestamp('nirvana_last_seen_at')->nullable();
            $table->timestamp('nirvana_summary_sent_at')->nullable();
            $table->longText('nirvana_summary')->nullable();
            $table->json('nirvana_quote_state')->nullable();
        });
        Schema::create('nirvana_rate_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->longText('answers');
            $table->longText('result');
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();
        });
        Schema::table('nirvana_meetings', function (Blueprint $table) {
            $table->string('visitor_timezone', 64)->nullable();
            $table->timestamp('emailed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nirvana_meetings', fn (Blueprint $table) => $table->dropColumn(['visitor_timezone', 'emailed_at']));
        Schema::dropIfExists('nirvana_rate_requests');
        Schema::table('conversations', fn (Blueprint $table) => $table->dropColumn(['nirvana_end_requested_at', 'nirvana_last_seen_at', 'nirvana_summary_sent_at', 'nirvana_summary', 'nirvana_quote_state']));
    }
};
