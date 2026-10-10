<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\NodeSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MachineController extends Controller
{
    /**
     * 获取机器列表（附带关联节点数）
     */
    public function fetch(Request $request)
    {
        $machines = ServerMachine::withCount('servers')
            ->orderBy('id')
            ->get()
            ->map(function (ServerMachine $machine) {
                return [
                    'id' => $machine->id,
                    'name' => $machine->name,
                    'notes' => $machine->notes,
                    'machine_type' => $machine->machine_type,
                    'relay_to_machine_id' => $machine->relay_to_machine_id,
                    'relay_to_node_id' => $machine->relay_to_node_id,
                    'relay_ports' => $machine->relay_ports,
                    'relay_landing_host' => $machine->relay_landing_host,
                    'relay_status' => $machine->relay_status,
                    'is_active' => $machine->is_active,
                    'last_seen_at' => $machine->last_seen_at,
                    'agent_version' => $machine->agent_version,
                    'upgrade_status' => $machine->load_status['upgrade_status'] ?? null,
                    // 离线判定：3 个心跳周期（server_push_interval 默认 60s）无上报视为离线
                    'is_online' => $machine->last_seen_at !== null
                        && $machine->last_seen_at > now()->subSeconds(max(180, (int) admin_setting('server_push_interval', 60) * 3))->timestamp,
                    'load_status' => $machine->load_status,
                    'servers_count' => $machine->servers_count,
                    'created_at' => $machine->created_at,
                    'updated_at' => $machine->updated_at,
                ];
            });

        return $this->success($machines);
    }

    /**
     * 创建 / 更新机器
     */
    public function save(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_server_machine,id',
            'name' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'machine_type' => 'nullable|in:access,landing',
            'relay_to_machine_id' => 'nullable|integer|exists:v2_server_machine,id',
            'relay_to_node_id' => 'nullable|integer|exists:v2_server,id',
            'relay_ports' => 'nullable|string|max:255',
            'relay_landing_host' => 'nullable|ip|max:64',
            'is_active' => 'nullable|boolean',
        ]);

        if (!empty($params['id'])) {
            $machine = ServerMachine::find($params['id']);
            $update = ['name' => $params['name']];
            if (array_key_exists('notes', $params)) {
                $update['notes'] = $params['notes'];
            }
            if (array_key_exists('machine_type', $params)) {
                $update['machine_type'] = $params['machine_type'] ?: null;
            }
            if (array_key_exists('relay_to_machine_id', $params)) {
                $update['relay_to_machine_id'] = $params['relay_to_machine_id'] ?: null;
            }
            if (array_key_exists('relay_ports', $params)) {
                $update['relay_ports'] = trim($params['relay_ports']) ?: null;
            }
            if (array_key_exists('relay_landing_host', $params)) {
                $update['relay_landing_host'] = trim($params['relay_landing_host']) ?: null;
            }
            if (array_key_exists('relay_to_node_id', $params)) {
                $nodeId = $params['relay_to_node_id'] ?: null;
                $update['relay_to_node_id'] = $nodeId;
                if ($nodeId) {
                    // 落地节点决定转发目标 host；机器级关联同步指向节点所在机器
                    $node = Server::find($nodeId);
                    if ($node && $node->machine_id) {
                        $update['relay_to_machine_id'] = $node->machine_id;
                    }
                    // 配置了转发却未显式给类型 → 自动标记为接入
                    if (!array_key_exists('machine_type', $params) && empty($machine->machine_type)) {
                        $update['machine_type'] = 'access';
                    }
                } else {
                    $update['relay_to_machine_id'] = null;
                }
            }
            if (array_key_exists('is_active', $params)) {
                $update['is_active'] = $params['is_active'];
            }
            $machine->update($update);

            // relay 相关字段出现即推送当前 spec——含取消场景（enabled=false 拆除转发）
            $relayKeys = ['machine_type', 'relay_to_machine_id', 'relay_to_node_id', 'relay_ports', 'relay_landing_host'];
            if (collect($relayKeys)->some(fn ($k) => array_key_exists($k, $params))) {
                NodeSyncService::pushMachine($machine->id, 'sync.relay', $machine->refresh()->relaySpec());
            }

            return $this->success(true);
        }

        $machine = ServerMachine::create([
            'name' => $params['name'],
            'notes' => $params['notes'] ?? null,
                'machine_type' => $params['machine_type'] ?? null,
                'relay_to_machine_id' => $params['relay_to_machine_id'] ?? null,
                'relay_to_node_id' => $params['relay_to_node_id'] ?? null,
                'relay_ports' => $params['relay_ports'] ?? null,
            'is_active' => $params['is_active'] ?? true,
            'token' => ServerMachine::generateToken(),
        ]);

        return $this->success([
            'id' => $machine->id,
            'token' => $machine->token,
            'install_command' => $this->buildInstallCommand($request, $machine),
        ]);
    }

    /**
     * 重置机器 Token
     */
    public function resetToken(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);
        $token = ServerMachine::generateToken();
        $machine->update(['token' => $token]);

        return $this->success(['token' => $token]);
    }

    /**
     * 获取机器 Token（仅展示一次，用于首次配置）
     */
    public function getToken(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);

        return $this->success(['token' => $machine->token]);
    }

    /**
     * 获取机器模式一键安装命令
     */
    public function installCommand(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);

        return $this->success([
            'command' => $this->buildInstallCommand($request, $machine),
        ]);
    }

    /**
     * 删除机器（自动解除关联节点）
     */
    public function drop(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $machine = ServerMachine::find($params['id']);
        $machineId = $machine->id;

        // Detach nodes first (sets machine_id = null), then delete and notify
        Server::where('machine_id', $machineId)->update(['machine_id' => null]);
        $machine->delete();

        // Notify with empty node list so WS process cleans up registry
        NodeSyncService::notifyMachineNodesChanged($machineId);

        return $this->success(true);
    }

    /**
     * 获取机器下的节点列表
     */
    public function nodes(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
        ]);

        $nodes = Server::where('machine_id', $params['machine_id'])
            ->orderBy('sort')
            ->get(['id', 'name', 'type', 'host', 'port', 'show', 'enabled', 'sort', 'protocol_settings']);

        return $this->success($nodes);
    }

    /**
     * 远程指令：reload（node_id>0 重载单节点，缺省整台机器）
     */
    public function controlReload(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
            'node_id' => 'nullable|integer',
        ]);

        $machine = ServerMachine::findOrFail($params['machine_id']);
        if (!$machine->is_active) {
            return $this->fail([400, '机器已停用']);
        }

        NodeSyncService::pushMachine($machine->id, 'control.reload', array_filter([
            'node_id' => $params['node_id'] ?? null,
        ], fn ($v) => $v !== null));

        return $this->success(true);
    }

    /**
     * 远程指令：restart（node_id>0 重启单节点进程，缺省重启 agent）
     */
    public function controlRestart(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
            'node_id' => 'nullable|integer',
        ]);

        $machine = ServerMachine::findOrFail($params['machine_id']);
        if (!$machine->is_active) {
            return $this->fail([400, '机器已停用']);
        }

        NodeSyncService::pushMachine($machine->id, 'control.restart', array_filter([
            'node_id' => $params['node_id'] ?? null,
        ], fn ($v) => $v !== null));

        return $this->success(true);
    }

    /**
     * 远程指令：upgrade（面板下发 agent 自升级，version + 至少一种架构的 SHA256 必填；
     * agent 端强制校验固定哈希，未固定的升级指令会被拒绝）
     */
    public function controlUpgrade(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
            'version' => 'required|string|max:64',
            'sha256_amd64' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'sha256_arm64' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ], [
            'sha256_amd64.size' => 'AMD64 SHA256 必须是 64 位十六进制',
            'sha256_arm64.size' => 'ARM64 SHA256 必须是 64 位十六进制',
        ]);

        if (empty($params['sha256_amd64']) && empty($params['sha256_arm64'])) {
            return $this->fail([400, '至少提供一种架构的 SHA256：agent 拒绝执行无固定哈希的升级']);
        }

        $machine = ServerMachine::findOrFail($params['machine_id']);
        if (!$machine->is_active) {
            return $this->fail([400, '机器已停用']);
        }

        NodeSyncService::pushMachine($machine->id, 'control.upgrade', array_filter([
            'version' => $params['version'],
            'sha256_amd64' => $params['sha256_amd64'] ?? null,
            'sha256_arm64' => $params['sha256_arm64'] ?? null,
        ], fn ($v) => $v !== null));

        return $this->success(true);
    }

    /**
     * agent 发行列表（GitHub releases，缓存 5 分钟；含各架构二进制的 SHA256 摘要）。
     * 上游 API 不可达时返回失败，前端可退回手动填入版本与哈希。
     */
    public function agentReleases()
    {
        if (Cache::has('agent_releases_list')) {
            return $this->success(Cache::get('agent_releases_list'));
        }
        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 10,
                'headers' => ['Accept' => 'application/vnd.github+json'],
            ]);
            $res = $client->get('https://api.github.com/repos/coolcrow/Xboard-Node/releases?per_page=15');
            $list = json_decode((string) $res->getBody(), true) ?: [];
            $out = [];
            foreach ($list as $r) {
                $digest = function (string $name) use ($r): string {
                    foreach ($r['assets'] ?? [] as $a) {
                        if (($a['name'] ?? '') === $name) {
                            $d = (string) ($a['digest'] ?? '');
                            return str_starts_with($d, 'sha256:') ? substr($d, 7) : '';
                        }
                    }
                    return '';
                };
                $out[] = [
                    'tag' => (string) ($r['tag_name'] ?? ''),
                    'published_at' => (string) ($r['published_at'] ?? ''),
                    'sha256_amd64' => $digest('xboard-node-linux-amd64'),
                    'sha256_arm64' => $digest('xboard-node-linux-arm64'),
                ];
            }
            Cache::put('agent_releases_list', $out, 300);
            return $this->success($out);
        } catch (\Throwable $e) {
            return $this->fail([500, '发行列表获取失败（' . $e->getMessage() . '），可手动填入版本与 SHA256']);
        }
    }

    /**
     * 获取机器负载历史
     */
    public function history(Request $request)
    {
        $params = $request->validate([
            'machine_id' => 'required|integer|exists:v2_server_machine,id',
            'limit' => 'nullable|integer|min:10|max:1440',
            'range_hours' => 'nullable|integer|min:1|max:24',
        ]);

        $query = ServerMachineLoadHistory::query()
            ->where('machine_id', $params['machine_id']);

        if (!empty($params['range_hours'])) {
            $query->where('recorded_at', '>=', now()->subHours((int) $params['range_hours'])->timestamp);
        }

        $limit = (int) ($params['limit'] ?? 60);

        $history = $query
            ->orderByDesc('recorded_at')
            ->limit($limit)
            ->get([
                'cpu',
                'mem_total',
                'mem_used',
                'disk_total',
                'disk_used',
                'net_in_speed',
                'net_out_speed',
                'recorded_at',
            ])
            ->reverse()
            ->values();

        return $this->success($history);
    }

    private function buildInstallCommand(Request $request, ServerMachine $machine): string
    {
        $panelUrl = rtrim((string) (admin_setting('app_url') ?: $request->getSchemeAndHttpHost()), '/');
        $installerUrl = (string) admin_setting(
            'node_installer_url',
            'https://raw.githubusercontent.com/coolcrow/Xboard-Node/main/install.sh'
        );

        return sprintf(
            'curl -fsSL %s | sudo bash -s -- --mode machine --panel %s --token %s --machine-id %d',
            $installerUrl,
            escapeshellarg($panelUrl),
            escapeshellarg($machine->token),
            $machine->id
        );
    }
}
