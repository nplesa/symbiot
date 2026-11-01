
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('username')->nullable()->unique();
            $table->string('phone', 30)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('avatar')->nullable();

            $table->string('address_country', 100)->nullable();
            $table->string('county', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('street', 255)->nullable();
            $table->string('postal_code', 20)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
