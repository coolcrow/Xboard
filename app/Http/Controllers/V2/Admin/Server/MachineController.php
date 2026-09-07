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
                    'is_active' => $machine->is_active,
                    'last_seen_at' => $machine->last_seen_at,
                    'agent_version' => $machine->agent_version,
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
            'is_active' => 'nullable|boolean',
        ]);

        if (!empty($params['id'])) {
            $machine = ServerMachine::find($params['id']);
            $update = ['name' => $params['name']];
            if (array_key_exists('notes', $params)) {
                $update['notes'] = $params['notes'];
            }
            if (array_key_exists('is_active', $params)) {
                $update['is_active'] = $params['is_active'];
            }
            $machine->update($update);
            return $this->success(true);
        }

        $machine = ServerMachine::create([
            'name' => $params['name'],
            'notes' => $params['notes'] ?? null,
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
            ->get(['id', 'name', 'type', 'host', 'port', 'show', 'enabled', 'sort']);

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
            'sha256_amd64' => 'nullable|string|size:64',
            'sha256_arm64' => 'nullable|string|size:64',
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
