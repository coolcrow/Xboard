<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 节点的接入归属：entry_machine_id = 承载其入口的接入机。
 * 取代三条件推断（端口匹配+host 启发式）——推断已引发三次显示/配置事故
 * （relaySpec 自环、徽章消失、落地 IP 失效）。显式字段优于推断（DESIGN.md 规则5/6）。
 * 由转发配置保存时服务端自动同步（ServerMachine::syncEntryMachineIds）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->unsignedBigInteger('entry_machine_id')->nullable()->after('machine_id')->comment('入口承载机（接入机）ID');
        });
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropColumn('entry_machine_id');
        });
    }
};
