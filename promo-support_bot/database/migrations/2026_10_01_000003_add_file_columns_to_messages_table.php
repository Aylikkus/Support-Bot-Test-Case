<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Telegram file_id is an opaque string (e.g. "BQACAgIAAxkB..."), so it cannot be a BIGINT.
        Schema::table('messages', function (Blueprint $table) {
            $table->text('file_id')->nullable();
            $table->text('file_name')->nullable();
        });

        // Files used to keep {"file_id", "name"} JSON in image_path.
        DB::table('messages')->where('message_type', 'file')->whereNotNull('image_path')
            ->get()
            ->each(function (object $row): void {
                $data = json_decode($row->image_path, true);

                DB::table('messages')->where('message_id', $row->message_id)->update([
                    'file_id' => is_array($data) ? ($data['file_id'] ?? null) : null,
                    'file_name' => is_array($data) ? ($data['name'] ?? null) : null,
                    'image_path' => null,
                ]);
            });
    }

    public function down(): void
    {
        DB::table('messages')->where('message_type', 'file')->whereNotNull('file_id')
            ->get()
            ->each(function (object $row): void {
                DB::table('messages')->where('message_id', $row->message_id)->update([
                    'image_path' => json_encode(['file_id' => $row->file_id, 'name' => $row->file_name ?? 'file'], JSON_UNESCAPED_UNICODE),
                ]);
            });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['file_id', 'file_name']);
        });
    }
};
