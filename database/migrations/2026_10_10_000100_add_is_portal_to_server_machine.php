<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 面板角色标记：portal（订阅来源·控制面入口）。
 * 与 machine_type（数据面角色 access/landing）正交——一台机器可同时是面板宿主和接入机。
 * 部署位置是运维知识，不可从节点数据推断（fail-safe 原则：不猜），故用显式标记。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->boolean('is_portal')->default(false)->after('machine_type')->comment('面板宿主（订阅来源）');
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->dropColumn('is_portal');
        });
    }
};
