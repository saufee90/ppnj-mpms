<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mills', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Kilang Sawit Bukit Bujang, Kilang Sawit PPNJ Kahang
            $table->string('code')->unique(); // BBJ, KHG
            $table->string('location'); // Segamat, Johor / Kluang, Johor
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mills');
    }
};
