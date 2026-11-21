<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('visitor_token_hash', 64)->nullable()->unique();
            $table->text('chat_brief')->nullable();
        });

        Schema::create('nirvana_rates', function (Blueprint $table) {
            $table->id();
            $table->string('version', 64);
            $table->unsignedTinyInteger('age');
            $table->string('gender', 12);
            $table->unsignedInteger('face_amount');
            $table->unsignedTinyInteger('term');
            $table->json('premiums');
            $table->unique(['version', 'age', 'gender', 'face_amount', 'term'], 'nirvana_rate_lookup');
        });

        Schema::create('nirvana_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('host');
            $table->dateTime('starts_at');
            $table->string('status');
            $table->string('zoom_id')->nullable()->unique();
            $table->text('join_url')->nullable();
            $table->text('recap')->nullable();
            $table->timestamps();
            $table->unique(['host', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nirvana_meetings');
        Schema::dropIfExists('nirvana_rates');
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['visitor_token_hash']);
            $table->dropColumn(['visitor_token_hash', 'chat_brief']);
        });
    }
};
