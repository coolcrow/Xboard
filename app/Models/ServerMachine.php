<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * App\Models\ServerMachine
 *
 * @property int $id
 * @property string $name 机器名称
 * @property string $token 认证 Token
 * @property string|null $notes 备注
 * @property bool $is_active 是否启用
 * @property int|null $last_seen_at 最后心跳时间
 * @property array|null $load_status 负载状态
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Server> $servers 关联的节点
 */
class ServerMachine extends Model
{
    protected $table = 'v2_server_machine';

    protected $guarded = ['id'];

    protected $casts = [
        'relay_forwards' => 'array',
        'is_active' => 'boolean',
        'last_seen_at' => 'integer',
        'load_status' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    protected $hidden = ['token'];

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class, 'machine_id');
    }

    public function loadHistory(): HasMany
    {
        return $this->hasMany(ServerMachineLoadHistory::class, 'machine_id');
    }

    /**
     * 生成新的随机 Token
     */
    public static function generateToken(): string
    {
        return Str::random(32);
    }

    /**
     * 更新最后心跳时间
     */
    public function updateHeartbeat(): bool
    {
        return $this->forceFill(['last_seen_at' => now()->timestamp])->save();
    }

    /**
     * 同步落地机上节点的接入归属（entry_machine_id）。
     * 转发配置变更时由控制器调用：先清空指向本机的旧归属，再按端口匹配重新标记。
     * 判别条件：端口 ∈ relay_ports 且 host ≠ 落地解析结果（显式覆盖列优先，其次主流 host）；
     * 无法判别时跳过该节点（fail-safe：宁缺勿错）。
     */
    public function syncEntryMachineIds(): void
    {
        // 只清理落地机上的服务节点（entry_machine_id 不属于服务节点）；
        // 纯入口坐标节点（未绑定机器，如 GZ1-*）的归属由节点保存维护，不在此动
        if (!empty($this->relay_to_machine_id)) {
            Server::query()
                ->where('entry_machine_id', $this->id)
                ->where('machine_id', $this->relay_to_machine_id)
                ->update(['entry_machine_id' => null]);
        }

        if ($this->machine_type !== 'access'
            || empty($this->relay_to_machine_id)) {
            return;
        }

        // 端口源：relaySpec（forwards 新模式与旧模式统一推导）；spec 未启用时
        // 回退旧列（保留 legacy 机器无 relay_to_node_id 时的同步行为）
        $spec = $this->relaySpec();
        $portsSource = $spec['enabled'] ? (string) $spec['ports'] : (string) ($this->relay_ports ?? '');
        if (trim($portsSource) === '') {
            return;
        }

        $ports = [];
        foreach (preg_split('/[\s,]+/', trim($portsSource)) as $raw) {
            if ($raw === '') {
                continue;
            }
            $ports[] = (int) (str_contains($raw, ':') ? explode(':', $raw)[0] : explode('/', $raw)[0]);
        }
        $ports = array_values(array_filter($ports, fn ($p) => $p > 0));
        if (empty($ports)) {
            return;
        }

        $landingHost = $this->relay_landing_host;
        if (empty($landingHost)) {
            $hosts = Server::query()->where('machine_id', $this->relay_to_machine_id)
                ->pluck('host')->filter()->countBy();
            $landingHost = $hosts->count() > 1 ? $hosts->sortDesc()->keys()->first() : null;
        }

        Server::query()
            ->where('machine_id', $this->relay_to_machine_id)
            ->whereIn('port', $ports)
            ->when(!empty($landingHost), fn ($q) => $q->where('host', '!=', $landingHost))
            ->update(['entry_machine_id' => $this->id]);
    }

    /**
     * forwards 模式推导 relay 规格：[{frontend, proto, node_id}] →
     * landing_host = 所选节点 host（必须同机同 host；relay_landing_host 仍可覆盖）；
     * 后端端口 = 节点 server_port；落地机 = 节点 machine_id。
     * 任一校验不过 → 禁用（fail-safe，与旧模式一致：宁可禁用不猜）。
     */
    private function relaySpecFromForwards(array $forwards): ?array
    {
        $disabled = ['enabled' => false, 'landing_host' => '', 'ports' => '', 'landing_machine_id' => 0];
        $nodeIds = [];
        $ports = [];
        foreach ($forwards as $f) {
            $frontend = (int) ($f['frontend'] ?? 0);
            $proto = strtolower(trim((string) ($f['proto'] ?? '')));
            $nodeId = (int) ($f['node_id'] ?? 0);
            if ($frontend < 1 || $frontend > 65535 || !in_array($proto, ['tcp', 'udp'], true) || $nodeId <= 0) {
                return $disabled;
            }
            $node = Server::query()->find($nodeId);
            // 目标必须是落地服务节点：绑定了机器 + 有内核端口 + 有 host
            if (!$node || empty($node->machine_id) || (int) $node->server_port < 1 || empty($node->host)) {
                return $disabled;
            }
            $nodeIds[$nodeId] = $node;
            $backend = (int) $node->server_port;
            $ports[] = $frontend === $backend
                ? "{$frontend}/{$proto}"
                : "{$frontend}:{$backend}/{$proto}";
        }
        $nodes = array_values($nodeIds);
        if (empty($nodes)) {
            return $disabled;
        }
        $landingMachineId = (int) $nodes[0]->machine_id;
        foreach ($nodes as $n) {
            if ((int) $n->machine_id !== $landingMachineId) {
                return $disabled; // 跨落地机：当前单落地关系模型不支持，禁用
            }
        }
        $hosts = collect($nodes)->pluck('host')->filter()->unique()->values();
        if ($hosts->count() !== 1) {
            return $disabled; // 同机多 host（不合规数据）：禁用不猜
        }
        return [
            'enabled' => true,
            'landing_host' => !empty($this->relay_landing_host) ? $this->relay_landing_host : $hosts->first(),
            'ports' => implode(',', $ports),
            'landing_machine_id' => $landingMachineId,
        ];
    }

    private function landingHostContext(Server $node): string
    {
        return (string) ($node->host ?? '');
    }

    /**
     * 生成下发给 agent 的 relay 规格（sync.relay 事件与 machine/nodes 接口共用）
     *
     * enabled=false 时 agent 会拆除本机 realm 转发；因此任何未启用状态都要下发，
     * 不能返回 null——否则 agent 无从得知转发已被取消。
     */
    public function relaySpec(): array
    {
        $spec = [
            'enabled' => false,
            'landing_host' => '',
            'ports' => (string) ($this->relay_ports ?? ''),
            'landing_machine_id' => (int) ($this->relay_to_machine_id ?? 0),
        ];

        // 新模式：relay_forwards（选落地服务节点，host/后端端口/落地机全部由节点推导）
        $forwards = $this->relay_forwards ?: [];
        if ($this->machine_type === 'access' && !empty($forwards)) {
            return $this->relaySpecFromForwards($forwards) ?? $spec;
        }

        if ($this->machine_type !== 'access'
            || empty($this->relay_to_node_id)
            || empty($this->relay_ports)) {
            return $spec;
        }

        $node = Server::query()->find($this->relay_to_node_id);
        if (!$node) {
            return $spec;
        }

        $spec['enabled'] = true;
        if (!empty($node->machine_id)) {
            $spec['landing_machine_id'] = (int) $node->machine_id;
        }

        // 落地 host 解析优先级：
        // ① 显式覆盖列（纯中转形态必填——节点 host 全指向接入机，推断必错）
        // ② 同机节点"主流 host"（直连+接入双节点形态：多数 host = 直连 IP）
        // ③ 都没有 → 宁可禁用也不能猜：错误指向会制造 realm 自环
        $siblingNodes = !empty($node->machine_id)
            ? Server::query()->where('machine_id', $node->machine_id)->get(['host', 'port', 'server_port'])
            : collect([$node]);
        $hostCounts = $siblingNodes->filter(fn ($n) => !empty($n->host))->countBy('host');
        if (!empty($this->relay_landing_host)) {
            $spec['landing_host'] = $this->relay_landing_host;
        } elseif ($hostCounts->isEmpty()) {
            return ['enabled' => false, 'landing_host' => '', 'ports' => '', 'landing_machine_id' => 0];
        } elseif ($hostCounts->count() === 1 && $hostCounts->keys()->first() === $this->landingHostContext($node)) {
            // 单一 host 且等于所选节点 host（纯中转形态特征）→ 无法区分，禁用
            return ['enabled' => false, 'landing_host' => '', 'ports' => '', 'landing_machine_id' => 0];
        } else {
            $spec['landing_host'] = $hostCounts->sortDesc()->keys()->first();
        }

        // 端口解析：落地机上用户端口 == 入口端口 的节点若设置了 server_port（同机
        // 双节点错开内核监听端口），转发目标自动映射到内核端口——ports 变为 entry:backend。
        // 找不到对应节点时按同端口转发（单节点落地机的常规形态）。
        $resolved = [];
        foreach (preg_split('/[\s,]+/', trim((string) $this->relay_ports)) as $raw) {
            if ($raw === '' || str_contains($raw, ':')) {
                $resolved[] = $raw; // 已是映射语法或空，原样保留
                continue;
            }
            // 协议后缀（443/udp）必须保留——接入机 TCP 443 被占等场景靠它分桶
            $suffix = str_contains($raw, '/') ? substr($raw, strrpos($raw, '/')) : '';
            $entryPort = (int) $raw;
            // 同端口挂双节点（落地+接入）时优先取接入节点（server_port 已错开）：
            // ① 各内核服务各自路径，职责清晰；② 接入/落地同机的自检场景不会自环。
            $hit = $siblingNodes->first(fn ($n) => (int) $n->port === $entryPort
                && !empty($n->server_port) && (int) $n->server_port !== $entryPort)
                ?? $siblingNodes->first(fn ($n) => (int) $n->port === $entryPort);
            $backend = $hit && !empty($hit->server_port) ? (int) $hit->server_port : $entryPort;
            $resolved[] = ($backend === $entryPort ? (string) $entryPort : "{$entryPort}:{$backend}") . $suffix;
        }
        $spec['ports'] = implode(',', $resolved);

        return $spec;
    }
}
