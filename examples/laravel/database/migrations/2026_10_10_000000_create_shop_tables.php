<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The shop orders, and the chat of Ann and Ben.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users');
            $table->string('item');
            $table->string('status')->default('placed');
            $table->timestamps();
        });

        // The chat as the app keeps it, from the webhook (Message::keep): each message by the id of the room event that
        // brought it, and who reacted to it with what.
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->nullable()->unique();
            $table->foreignId('from_id')->constrained('users');
            $table->text('text');
            $table->json('reactions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('orders');
    }
};
