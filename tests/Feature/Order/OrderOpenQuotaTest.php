<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 开通配额回归（2026-10-03 真实购买实测：1GB 套餐开通后 transfer_enable=0）。
 * 根因：buyByPeriod 在内存设置配额后调 performReset，后者以行锁旧值整体
 * 覆盖内存模型——修复为重载时保留脏属性。本测试守护该语义。
 */
class OrderOpenQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function makePlan(int $gb, bool $onetime = false): Plan
    {
        return Plan::create([
            'group_id' => 1,
            'transfer_enable' => $gb,
            'name' => 'quota-test',
            'speed_limit' => 5,
            'show' => 1,
            'sell' => 1,
            'renew' => 1,
            'prices' => $onetime ? ['onetime' => 1] : ['monthly' => 1],
            'device_limit' => 1,
            'sort' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->email = 'quota-' . uniqid() . '@test.local';
        $user->password = 'x';
        $user->uuid = (string) \Illuminate\Support\Str::uuid();
        $user->token = 'qt' . uniqid();
        $user->transfer_enable = 0;
        $user->u = 123;
        $user->d = 456;
        $user->created_at = time();
        $user->updated_at = time();
        $user->save();
        return $user;
    }

    public function test_new_purchase_sets_full_quota_bytes(): void
    {
        $plan = $this->makePlan(1);
        $user = $this->makeUser();

        $order = Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period' => 'month_price',
            'trade_no' => 'QT' . uniqid(),
            'total_amount' => 1,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_PENDING,
        ]);

        (new OrderService($order))->paid('TESTCB' . uniqid());

        $user->refresh();
        $this->assertSame(1 * 1073741824, (int) $user->transfer_enable, '新购开通后配额必须是 plan GB × 1024^3 字节');
        $this->assertSame(0, (int) $user->u + (int) $user->d, '新购开通应重置已用流量');
        $this->assertSame($plan->id, $user->plan_id);
        $this->assertNotNull($user->expired_at);
    }

    public function test_cycle_renewal_extends_and_keeps_quota(): void
    {
        $plan = $this->makePlan(30);
        $user = $this->makeUser();
        $user->plan_id = $plan->id;
        $user->transfer_enable = 30 * 1073741824;
        $user->expired_at = time() + 86400 * 10;
        $user->save();

        $order = Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period' => 'month_price',
            'trade_no' => 'QT' . uniqid(),
            'total_amount' => 3000,
            'type' => Order::TYPE_RENEWAL,
            'status' => Order::STATUS_PENDING,
        ]);

        (new OrderService($order))->paid('TESTCB' . uniqid());

        $user->refresh();
        $this->assertSame(30 * 1073741824, (int) $user->transfer_enable, '续费不得丢配方额');
        $this->assertGreaterThan(time() + 86400 * 20, (int) $user->expired_at, '续费应在原到期时间上顺延');
    }

    public function test_onetime_purchase_sets_quota(): void
    {
        $plan = $this->makePlan(5, onetime: true);
        $user = $this->makeUser();

        $order = Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period' => 'onetime_price',
            'trade_no' => 'QT' . uniqid(),
            'total_amount' => 1,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_PENDING,
        ]);

        (new OrderService($order))->paid('TESTCB' . uniqid());

        $user->refresh();
        $this->assertSame(5 * 1073741824, (int) $user->transfer_enable, '一次性套餐开通后配额必须完整');
    }
}
