<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->string('group')->default('General');
            $table->timestamps();
        });

        Schema::create('permission_user', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'user_id']);
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('status')->default('new');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('track')->nullable();
            $table->text('message')->nullable();
            $table->json('payload');
            $table->timestamps();
            $table->index(['type', 'status']);
        });

        Schema::create('directory_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('track');
            $table->string('status')->default('pending');
            $table->string('name');
            $table->string('title')->nullable();
            $table->string('license_number');
            $table->string('npn')->nullable();
            $table->string('phone');
            $table->string('email');
            $table->string('city')->nullable();
            $table->string('state', 8)->nullable();
            $table->json('states_licensed')->nullable();
            $table->json('specialties')->nullable();
            $table->text('bio')->nullable();
            $table->string('photo_path')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['track', 'status']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone');
            $table->string('status')->default('new');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('sender');
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('seo_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('path');
            $table->string('label');
            $table->string('title');
            $table->text('meta_description')->nullable();
            $table->string('og_title')->nullable();
            $table->text('og_description')->nullable();
            $table->string('canonical')->nullable();
            $table->timestamps();
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_page_id')->constrained()->cascadeOnDelete();
            $table->string('question');
            $table->text('answer');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('seo_pages');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('directory_profiles');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('permission_user');
        Schema::dropIfExists('permissions');
    }
};
