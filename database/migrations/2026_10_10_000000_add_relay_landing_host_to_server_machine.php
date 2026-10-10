<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * relaySpec 的落地 host 覆盖列。
 * 背景：纯中转形态（落地机不留直连节点）下，节点 host 全部指向接入机，
 * "主流 host"推断会把落地指回接入机形成 realm 自环——此列让运维显式指定落地 IP。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->string('relay_landing_host', 64)->nullable()->after('relay_ports')->comment('显式落地 IP：纯中转形态下节点 host 无法推断时使用');
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->dropColumn('relay_landing_host');
        });
    }
};
