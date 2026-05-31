<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('user_unique', 32);
            $table->unsignedBigInteger('photo_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_unique', 'photo_id']);
            $table->index('photo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
