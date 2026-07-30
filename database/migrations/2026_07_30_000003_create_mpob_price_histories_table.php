<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mpob_price_histories', function (Blueprint $table) {
            $table->id();
            $table->string('category', 10);
            $table->date('trade_date');
            $table->decimal('price', 12, 2);
            $table->timestamp('source_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['trade_date', 'category'], 'mpob_price_histories_trade_date_category_unique');
            $table->index(['category', 'trade_date'], 'mpob_price_histories_category_trade_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpob_price_histories');
    }
};
