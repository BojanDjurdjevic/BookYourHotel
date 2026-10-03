<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_booking_challenges', function (Blueprint $table) {
            $table->string('id', 64)->primary(); // HMAC of the browser session ID
            $table->uuid('token'); // Rotated for each new code / intent
            $table->string('email');
            $table->text('payload');
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('resend_available_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_booking_challenges');
    }
};
