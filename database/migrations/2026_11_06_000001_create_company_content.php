<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('company_records', function(Blueprint $t) { $t->id(); $t->string('kind'); $t->json('data'); $t->timestamps(); });
  Schema::create('career_applications', function(Blueprint $t) { $t->id(); $t->unsignedBigInteger('job_id')->nullable(); $t->string('name'); $t->string('email'); $t->string('phone')->nullable(); $t->text('message')->nullable(); $t->string('resume'); $t->string('status')->default('new'); $t->text('notes')->nullable(); $t->timestamps(); });
 }
 public function down(): void { Schema::dropIfExists('career_applications'); Schema::dropIfExists('company_records'); }
};
