<?php

namespace Plugins\G7\Image\Delivery\Console\Commands;

use Illuminate\Console\Command;
use Plugins\G7\Image\Delivery\Services\VariantBuilder;
use Plugins\G7\Image\Delivery\Support\VariantPath;

/**
 * 중간 크기 변환본을 미생성분부터 소량씩 만든다.
 *
 * 스케줄러가 10분마다 `--scheduled --limit=20` 으로 부른다. 수동으로 크게 돌리려면
 * `--limit` 을 올려서 직접 실행한다.
 */
class BuildVariantsCommand extends Command
{
    protected $signature = 'g7-image-delivery:build-variants
        {--dry-run : 만들지 않고 계획만 출력한다}
        {--limit=20 : 이번 회차에 처리할 원본 수}
        {--scheduled : 스케줄러 호출 (설정이 꺼져 있으면 아무것도 하지 않는다)}';

    protected $description = '본문 이미지의 중간 크기 변환본을 생성합니다 (미생성분부터)';

    public function handle(VariantBuilder $builder): int
    {
        if ($this->option('scheduled') && ! plugin_setting(VariantPath::IDENTIFIER, 'variants_enabled', true)) {
            $this->info('변환본 생성이 설정에서 꺼져 있어 건너뜁니다.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        $result = $builder->buildPending($limit, $dryRun);

        foreach ($result['items'] as $item) {
            $this->line(sprintf(
                '  %-12s %-14s %s',
                $item['hash'] ?? '-',
                $item['status'] ?? '-',
                $item['variants'] ?? ($item['reason'] ?? '')
            ));
        }

        $this->newLine();
        $this->info(sprintf(
            '%s검사 %d · 생성 %d · 건너뜀 %d · 실패 %d',
            $dryRun ? '[모의] ' : '',
            $result['scanned'],
            $result['built'],
            $result['skipped'],
            $result['failed']
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
