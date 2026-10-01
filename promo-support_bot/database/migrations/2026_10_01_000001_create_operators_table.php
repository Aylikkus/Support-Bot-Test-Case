<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operators', function (Blueprint $table) {
            $table->id('operator_id');
            $table->text('name')->unique();
            $table->text('password');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operators');
    }
};
