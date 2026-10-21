<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            // 转发映射（接入机专用）：[{"frontend":18443,"proto":"tcp","node_id":18}, ...]
            // 目标为落地"服务节点"——host(落地地址)/server_port(后端端口)/machine_id(落地机关系)
            // 全部由节点推导，取代手填 relay_landing_host 与 :backend 端口语法。
            // 旧三字段保留：relay_forwards 为空时走旧逻辑（向后兼容）。
            $table->text('relay_forwards')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->dropColumn('relay_forwards');
        });
    }
};
