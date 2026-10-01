<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id('message_id');
            $table->foreignId('request_id')->constrained('requests', 'request_id');
            $table->bigInteger('user_id')->nullable();
            $table->bigInteger('telegram_message_id');
            $table->enum('sender_type', ['user', 'bot']);
            $table->text('text');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
