<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('operator_id')->nullable()->constrained('operators', 'operator_id');
            $table->enum('message_type', ['text', 'image', 'file'])->default('text');
            $table->text('image_path')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            // Laravel's enum() on PostgreSQL is varchar + CHECK; change() would leave the old CHECK in place.
            DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_sender_type_check');
            DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_sender_type_check CHECK (sender_type IN ('user', 'bot', 'operator'))");
            DB::statement('ALTER TABLE messages ALTER COLUMN text DROP NOT NULL');

            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->enum('sender_type', ['user', 'bot', 'operator'])->change();
            $table->text('text')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('messages')->where('sender_type', 'operator')->update(['sender_type' => 'bot']);
        DB::table('messages')->whereNull('text')->update(['text' => '']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_sender_type_check');
            DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_sender_type_check CHECK (sender_type IN ('user', 'bot'))");
            DB::statement('ALTER TABLE messages ALTER COLUMN text SET NOT NULL');
        } else {
            Schema::table('messages', function (Blueprint $table) {
                $table->enum('sender_type', ['user', 'bot'])->change();
                $table->text('text')->nullable(false)->change();
            });
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('operator_id');
            $table->dropColumn(['message_type', 'image_path']);
        });
    }
};
