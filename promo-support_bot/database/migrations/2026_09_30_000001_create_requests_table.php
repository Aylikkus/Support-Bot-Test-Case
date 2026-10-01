<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requests', function (Blueprint $table) {
            $table->id('request_id');
            $table->bigInteger('user_id');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->boolean('forwarded_to_operator')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('forwarded_at')->nullable();
            $table->timestamp('closed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requests');
    }
};
