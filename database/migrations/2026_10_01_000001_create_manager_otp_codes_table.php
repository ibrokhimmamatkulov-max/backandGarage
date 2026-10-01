<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Одноразовые коды для входа менеджера по телефону.
 *
 * Отдельно от owner_otp_codes: это разные учётки (users и owners) и разные
 * контуры авторизации — код, выданный владельцу, не должен подходить
 * для входа в админку с тем же номером, и наоборот.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('manager_otp_codes')) {
            return;
        }

        Schema::create('manager_otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20);
            $table->string('code_hash');
            $table->string('purpose', 32)->default('auth');
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['phone', 'purpose', 'consumed_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_otp_codes');
    }
};
