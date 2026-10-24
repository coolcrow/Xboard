<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            // 机器公网 IP——UX-AUDIT 断点 F：接入机 IP 是节点 host/DNS 的前置依赖，
            // 此前无处记录，客户节点 host 填错无从校验
            $table->string('ip', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->dropColumn('ip');
        });
    }
};
