<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
            $table->timestamp('approved_at')->nullable()->after('is_admin');
            $table->string('registration_ip', 45)->nullable()->after('approved_at');
            $table->string('country', 100)->nullable()->after('registration_ip');
        });

        DB::table('users')->update(['approved_at' => now()]);
        DB::table('users')->whereIn('email', ['nicu.plesa@gmail.com', 'nicolae.plesa.a7@gmail.com'])->update(['is_admin' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'approved_at', 'registration_ip', 'country']);
        });
    }
};
