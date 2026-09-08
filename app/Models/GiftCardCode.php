<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App\Models\GiftCardCode
 *
 * @property int $id
 * @property int $template_id 模板ID
 * @property GiftCardTemplate $template 关联模板
 * @property string $code 兑换码
 * @property string|null $batch_id 批次ID
 * @property int $status 状态
 * @property int|null $user_id 使用用户ID
 * @property int|null $used_at 使用时间
 * @property int|null $expires_at 过期时间
 * @property array|null $actual_rewards 实际奖励
 * @property int $usage_count 使用次数
 * @property int $max_usage 最大使用次数
 * @property array|null $metadata 额外数据
 * @property int $created_at
 * @property int $updated_at
 */
class GiftCardCode extends Model
{
    protected $table = 'v2_gift_card_code';
    protected $dateFormat = 'U';

    // 状态常量
    const STATUS_UNUSED = 0;        // 未使用
    const STATUS_USED = 1;          // 已使用
    const STATUS_EXPIRED = 2;       // 已过期
    const STATUS_DISABLED = 3;      // 已禁用

    protected $fillable = [
        'template_id',
        'code',
        'batch_id',
        'status',
        'user_id',
        'used_at',
        'expires_at',
        'actual_rewards',
        'usage_count',
        'max_usage',
        'metadata'
    ];

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'used_at' => 'timestamp',
        'expires_at' => 'timestamp',
        'actual_rewards' => 'array',
        'metadata' => 'array'
    ];

    /**
     * 获取状态映射
     */
    public static function getStatusMap(): array
    {
        return [
            self::STATUS_UNUSED => '未使用',
            self::STATUS_USED => '已使用',
            self::STATUS_EXPIRED => '已过期',
            self::STATUS_DISABLED => '已禁用',
        ];
    }

    /**
     * 获取状态名称
     */
    public function getStatusNameAttribute(): string
    {
        return self::getStatusMap()[$this->status] ?? '未知状态';
    }

    /**
     * 关联礼品卡模板
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(GiftCardTemplate::class, 'template_id');
    }

    /**
     * 关联使用用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 关联使用记录
     */
    public function usages(): HasMany
    {
        return $this->hasMany(GiftCardUsage::class, 'code_id');
    }

    /**
     * 检查是否可用
     */
    public function isAvailable(): bool
    {
        // 检查状态
        if (in_array($this->status, [self::STATUS_EXPIRED, self::STATUS_DISABLED])) {
            return false;
        }

        // 检查是否过期
        if ($this->expires_at && $this->expires_at < time()) {
            return false;
        }

        // 检查使用次数
        if ($this->usage_count >= $this->max_usage) {
            return false;
        }

        return true;
    }

    /**
     * 检查是否已过期
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at < time();
    }

    /**
     * 标记为已使用
     */
    public function markAsUsed(User $user): bool
    {
        // 原子认领：仅当仍有可用次数时才占用一次。并发兑换同一张卡时，
        // 恰好一个请求影响到该行（usage_count < max_usage 条件更新），
        // 其余请求拿到 0 行 → 抛异常回滚整个兑换事务（奖励不发放）。
        // 同时修复多用途卡：仅最后一次用尽时才置 USED，中途保持 UNUSED。
        $claimed = self::where('id', $this->id)
            ->where('usage_count', '<', self::rawColumn('max_usage'))
            ->whereNotIn('status', [self::STATUS_EXPIRED, self::STATUS_DISABLED])
            ->update([
                'usage_count' => self::rawColumn('usage_count') . ' + 1',
                'user_id' => $user->id,
                'used_at' => time(),
            ]);
        if ($claimed === 0) {
            throw new \App\Exceptions\ApiException('礼品卡已被使用或不可用');
        }
        // 若本次为最后一次使用，标记 USED（独立条件更新，失败不影响计数正确性）
        self::where('id', $this->id)
            ->whereRaw('usage_count >= max_usage')
            ->update(['status' => self::STATUS_USED]);
        $this->refresh();
        return true;
    }

    private static function rawColumn(string $col): \Illuminate\Database\Query\Expression
    {
        return new \Illuminate\Database\Query\Expression($col);
    }

    /**
     * 标记为已过期
     */
    public function markAsExpired(): bool
    {
        $this->status = self::STATUS_EXPIRED;
        return $this->save();
    }

    /**
     * 标记为已禁用
     */
    public function markAsDisabled(): bool
    {
        $this->status = self::STATUS_DISABLED;
        return $this->save();
    }

    /**
     * 生成兑换码
     */
    public static function generateCode(string $prefix = 'GC'): string
    {
        do {
            $safePrefix = (string) $prefix;
            $code = $safePrefix . strtoupper(substr(md5(uniqid($safePrefix . mt_rand(), true)), 0, 12));
        } while (self::where('code', $code)->exists());

        return $code;
    }

    /**
     * 批量生成兑换码
     */
    public static function batchGenerate(int $templateId, int $count, array $options = []): string
    {
        $batchId = uniqid('batch_');
        $prefix = $options['prefix'] ?? 'GC';
        $expiresAt = $options['expires_at'] ?? null;
        $maxUsage = $options['max_usage'] ?? 1;

        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = [
                'template_id' => $templateId,
                'code' => self::generateCode($prefix),
                'batch_id' => $batchId,
                'status' => self::STATUS_UNUSED,
                'expires_at' => $expiresAt,
                'max_usage' => $maxUsage,
                'created_at' => time(),
                'updated_at' => time(),
            ];
        }

        self::insert($codes);

        return $batchId;
    }

    /**
     * 设置实际奖励（用于盲盒等）
     */
    public function setActualRewards(array $rewards): bool
    {
        $this->actual_rewards = $rewards;
        return $this->save();
    }

    /**
     * 获取实际奖励
     */
    public function getActualRewards(): array
    {
        return $this->actual_rewards ?? $this->template->rewards ?? [];
    }

    /**
     * 检查兑换码格式
     */
    public static function validateCodeFormat(string $code): bool
    {
        // 基本格式验证：字母数字组合，长度8-32
        return preg_match('/^[A-Z0-9]{8,32}$/', $code);
    }

    /**
     * 根据批次ID获取兑换码
     */
    public static function getByBatchId(string $batchId)
    {
        return self::where('batch_id', $batchId)->get();
    }

    /**
     * 清理过期兑换码
     */
    public static function cleanupExpired(): int
    {
        $count = self::where('status', self::STATUS_UNUSED)
            ->where('expires_at', '<', time())
            ->count();

        self::where('status', self::STATUS_UNUSED)
            ->where('expires_at', '<', time())
            ->update(['status' => self::STATUS_EXPIRED]);

        return $count;
    }
}