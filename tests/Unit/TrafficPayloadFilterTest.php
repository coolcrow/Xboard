<?php

namespace Tests\Unit;

use App\Services\ServerService;
use Tests\TestCase;

class TrafficPayloadFilterTest extends TestCase
{
    /**
     * 流量载荷清洗（计费完整性，评审 M-2）：V1/V2 上报共用闸门。
     */
    public function test_keeps_valid_entries(): void
    {
        $traffic = [
            1 => [1024, 2048],
            2 => [0, 0],
            3 => ['500', '600'],
        ];
        $this->assertSame($traffic, ServerService::filterTrafficPayload($traffic));
    }

    public function test_drops_negative_values(): void
    {
        $traffic = [
            1 => [1024, 2048],
            2 => [-1, 500],
            3 => [500, -1],
            4 => [-100, -100],
        ];
        $filtered = ServerService::filterTrafficPayload($traffic);
        $this->assertArrayHasKey(1, $filtered);
        $this->assertArrayNotHasKey(2, $filtered, '负上传不得进入计费（可被用于回充配额）');
        $this->assertArrayNotHasKey(3, $filtered, '负下载不得进入计费');
        $this->assertArrayNotHasKey(4, $filtered);
    }

    public function test_drops_malformed_entries(): void
    {
        $traffic = [
            1 => [1024],
            2 => [1, 2, 3],
            3 => 'not-an-array',
            4 => null,
            5 => ['abc', 'def'],
            6 => [1024, 2048],
        ];
        $filtered = ServerService::filterTrafficPayload($traffic);
        $this->assertSame([6 => [1024, 2048]], $filtered);
    }
}
