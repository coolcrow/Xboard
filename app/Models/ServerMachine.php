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
