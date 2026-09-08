<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\WebSocket\NodeWorker;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ServerController extends Controller
{
    /**
     * server handshake api
     */
    public function handshake(Request $request): JsonResponse
    {
        $websocket = ['enabled' => false];

        if ((bool) admin_setting('server_ws_enable', 1) && Cache::has(NodeWorker::HEARTBEAT_CACHE_KEY)) {
            $customUrl = trim((string) admin_setting('server_ws_url', ''));

            if ($customUrl !== '') {
                $wsUrl = rtrim($customUrl, '/');
            } else {
                $wsScheme = $request->isSecure() ? 'wss' : 'ws';
                $wsUrl = "{$wsScheme}://{$request->getHttpHost()}/ws";
            }

            $websocket = [
                'enabled' => true,
                'ws_url' => $wsUrl,
            ];
        }

        return response()->json([
            'websocket' => $websocket
        ]);
    }

    /**
     * node report api - merge traffic + alive + status + metrics
     */
    public function report(Request $request): JsonResponse
    {
        $node = $request->attributes->get('node_info');

        ServerService::touchNode($node);

        // report_id 幂等门：agent 每次成功上报推进序号、失败重发复用同值。
        // 本端记录每节点已处理的最大序号，重放报文（id <= 已处理）只刷新
        // 节点触达与状态遥测，不再重复入账流量/在线——消除
        // 「面板已入库但响应丢失 → agent 恢复重发 → 流量双计」
        $reportId = (int) $request->input('report_id', 0);
        $isReplay = false;
        if ($reportId > 0) {
            $seqKey = 'traffic:report_seq:' . $node->id;
            $lastId = (int) (Cache::get($seqKey) ?: 0);
            if ($reportId <= $lastId) {
                $isReplay = true;
            } else {
                Cache::put($seqKey, $reportId, 7 * 86400);
            }
        }

        $traffic = $request->input('traffic');
        if (!$isReplay && is_array($traffic) && !empty($traffic)) {
            ServerService::processTraffic($node, $traffic);
        }

        $alive = $request->input('alive');
        if (!$isReplay && is_array($alive) && !empty($alive)) {
            ServerService::processAlive($node->id, $alive);
        }

        $online = $request->input('online');
        if (!$isReplay && is_array($online) && !empty($online)) {
            ServerService::processOnline($node, $online);
        }

        $status = $request->input('status');
        if (is_array($status) && !empty($status)) {
            ServerService::processStatus($node, $status);
        }

        $metrics = $request->input('metrics');
        if (is_array($metrics) && !empty($metrics)) {
            ServerService::updateMetrics($node, $metrics);
        }

        return response()->json(['data' => true]);
    }
}
