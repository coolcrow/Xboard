<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * relay agent 化：转发端口清单、落地节点引用、agent 上报的 relay 运行状态
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->string('relay_ports', 255)->nullable()->after('relay_to_machine_id')->comment('转发端口清单，如 443,8443/tcp,53/udp');
            $table->unsignedBigInteger('relay_to_node_id')->nullable()->after('relay_ports')->comment('落地节点 ID（host 解析来源）');
            $table->string('relay_status', 512)->nullable()->after('relay_to_node_id')->comment('agent 上报的 relay 运行状态 JSON');
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->dropColumn(['relay_ports', 'relay_to_node_id', 'relay_status']);
        });
    }
};
