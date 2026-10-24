<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServerGroup;
use App\Models\ServerMachine;
use App\Models\Server;
use App\Models\User;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 安装向导：状态查询 + 快速初始化
 * 
 * UX-AUDIT.md 断点 A-F 的修复——客户不踩"没建分组/管理员没流量/没绑机器"的坑
 */
class SetupWizardController extends Controller
{
    /**
     * 向导状态：哪些步骤已完成、哪些缺失
     */
    public function status(): \Illuminate\Http\JsonResponse
    {
        $groupCount = ServerGroup::count();
        $admin = User::where('is_admin', 1)->first();
        $adminHasGroup = $admin && $admin->group_id;
        $adminHasTraffic = $admin && $admin->transfer_enable > 0;
        
        $machines = ServerMachine::where('is_active', 1)->get();
        $landingMachines = $machines->where('machine_type', 'landing');
        $accessMachines = $machines->where('machine_type', 'access');
        
        $serviceNodes = Server::whereNotNull('machine_id')->where('machine_id', '>', 0)->count();
        $entryNodes = Server::whereNotNull('entry_machine_id')->where('entry_machine_id', '>', 0)->count();
        
        $forwardingEnabled = false;
        foreach ($accessMachines as $m) {
            if ($m->relaySpec()['enabled'] ?? false) {
                $forwardingEnabled = true;
                break;
            }
        }
        
        $nodesInGroup = Server::whereNotNull('group_ids')
            ->get()
            ->filter(fn ($n) => !empty($n->group_ids) && !empty(array_filter($n->group_ids)))
            ->count();
        
        return $this->success([
                'steps' => [
                    'group' => ['done' => $groupCount > 0, 'label' => '创建分组'],
                    'admin' => ['done' => $adminHasGroup && $adminHasTraffic, 'label' => '配置管理员'],
                    'landing_machine' => ['done' => $landingMachines->count() > 0, 'label' => '添加落地机'],
                    'service_node' => ['done' => $serviceNodes > 0, 'label' => '创建服务节点'],
                    'access_machine' => ['done' => $accessMachines->count() > 0, 'label' => '添加接入机'],
                    'entry_node' => ['done' => $entryNodes > 0, 'label' => '创建入口节点'],
                    'forwarding' => ['done' => $forwardingEnabled, 'label' => '配置转发'],
                    'node_group' => ['done' => $nodesInGroup > 0, 'label' => '节点挂分组'],
                ],
                'all_done' => $groupCount > 0 && $adminHasGroup && $adminHasTraffic 
                    && $landingMachines->count() > 0 && $serviceNodes > 0
                    && $nodesInGroup > 0,
                'group_count' => $groupCount,
                'machine_count' => $machines->count(),
                'node_count' => Server::count(),
            ]);
    }
    
    /**
     * 快速初始化：一键 建分组 + 管理员挂组/给流量 + 建测试套餐
     * 断点 A+B+C 的一键修复
     */
    public function quickStart(Request $request): \Illuminate\Http\JsonResponse
    {
        $params = $request->validate([
            'group_name' => 'nullable|string|max:255',
        ]);
        
        $groupName = $params['group_name'] ?? '默认分组';
        $results = [];
        
        // ① 分组
        $group = ServerGroup::where('name', $groupName)->first();
        if (!$group) {
            $group = new ServerGroup();
            $group->name = $groupName;
            $group->save();
            $results['group'] = "created: {$groupName} (id={$group->id})";
        } else {
            $results['group'] = "exists: {$groupName} (id={$group->id})";
        }
        
        // ② 管理员
        $admin = User::where('is_admin', 1)->first();
        if ($admin) {
            $changed = false;
            if (!$admin->group_id) {
                $admin->group_id = $group->id;
                $changed = true;
            }
            if ($admin->transfer_enable <= 0) {
                $admin->transfer_enable = 107374182400; // 100GB
                $changed = true;
            }
            if (!$admin->expired_at || $admin->expired_at < time() + 86400 * 30) {
                $admin->expired_at = time() + 86400 * 365;
                $changed = true;
            }
            if ($changed) {
                $admin->save();
                $results['admin'] = 'configured';
            } else {
                $results['admin'] = 'already_ok';
            }
        }
        
        // ③ 测试套餐（绑分组）
        $plan = Plan::where('group_id', $group->id)->first();
        if (!$plan) {
            $plan = new Plan();
            $plan->name = '测试套餐';
            $plan->group_id = $group->id;
            $plan->transfer_enable = 10737418240; // 10GB
            $plan->prices = json_encode(['monthly' => 0.01]);
            $plan->show = 1;
            $plan->save();
            $results['plan'] = "created: 测试套餐 (id={$plan->id})";
        } else {
            $results['plan'] = "exists: {$plan->name}";
        }
        
        return $this->success($results);
    }
    
    /**
     * 现有节点批量挂分组（断点 D 修复）
     */
    public function assignAllNodesToGroup(Request $request): \Illuminate\Http\JsonResponse
    {
        $params = $request->validate([
            'group_id' => 'required|integer|exists:v2_group,id',
        ]);
        
        $gid = (string) $params['group_id'];
        $count = 0;
        Server::all()->each(function ($n) use ($gid, &$count) {
            $groups = $n->group_ids ?? [];
            if (!in_array($gid, $groups, true)) {
                $n->group_ids = array_merge($groups, [$gid]);
                $n->save();
                $count++;
            }
        });
        
        return $this->success(['assigned' => $count]);
    }
}
