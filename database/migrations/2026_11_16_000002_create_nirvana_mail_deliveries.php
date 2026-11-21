<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('nirvana_mail_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('recipient', 254);
            $table->timestamp('sent_at');
            $table->unique(['conversation_id', 'kind', 'recipient'], 'nirvana_mail_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nirvana_mail_deliveries');
    }
};
