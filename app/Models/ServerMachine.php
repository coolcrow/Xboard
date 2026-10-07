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
        if (!$node || empty($node->host)) {
            return $spec;
        }

        $spec['enabled'] = true;
        $spec['landing_host'] = $node->host;
        if (!empty($node->machine_id)) {
            $spec['landing_machine_id'] = (int) $node->machine_id;
        }

        return $spec;
    }
}
