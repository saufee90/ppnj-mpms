<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_report_notifications', function (Blueprint $table) {
            $table->id();
            $table->date('report_date');
            $table->string('channel')->default('email');
            $table->string('recipient');
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['report_date', 'channel', 'recipient']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_report_notifications');
    }
};