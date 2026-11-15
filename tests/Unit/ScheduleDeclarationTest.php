<?php

namespace Plugins\G7\Image\Delivery\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Image\Delivery\Plugin;

/**
 * 예약 작업 선언 — 변환본 생성 작업의 중복 실행 잠금 만료 시간 (0.3.1).
 *
 * 코어 7.1.5+ 는 `without_overlapping` 이 정수일 때만 그 분 뒤 잠금을 만료시킨다
 * (정수가 아니면 1440분). 그래서 값의 타입까지 확인한다.
 */
class ScheduleDeclarationTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private function schedulesByCommand(): array
    {
        if (! class_exists(Plugin::class, false)) {
            require_once dirname(__DIR__, 2).'/plugin.php';
        }

        $byCommand = [];
        foreach ((new Plugin)->getSchedules() as $config) {
            $byCommand[strtok($config['command'], ' ')] = $config;
        }

        return $byCommand;
    }

    public function test_변환본_생성은_10분_주기에_잠금_30분이다(): void
    {
        $build = $this->schedulesByCommand()['g7-image-delivery:build-variants'];

        $this->assertSame('*/10 * * * *', $build['schedule']);
        $this->assertSame(30, $build['without_overlapping']);
    }

    public function test_정리_작업은_코어_기본값을_쓴다(): void
    {
        $prune = $this->schedulesByCommand()['g7-image-delivery:prune-variants'];

        $this->assertSame('daily', $prune['schedule']);
        $this->assertFalse(array_key_exists('without_overlapping', $prune));
    }
}
