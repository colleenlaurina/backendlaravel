<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->default(0)->after('description');
            $table->enum('listing_type', ['sell', 'adopt'])->default('adopt')->after('price');
            $table->enum('status', ['available', 'adopted'])->default('available')->after('listing_type');
        });
    }

    public function down()
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn(['price', 'listing_type', 'status']);
        });
    }
};