<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->string('machine_type', 20)->nullable()->after('notes');
            $table->unsignedBigInteger('relay_to_machine_id')->nullable()->after('machine_type');
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->dropColumn(['machine_type', 'relay_to_machine_id']);
        });
    }
};
