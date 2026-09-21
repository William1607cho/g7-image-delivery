<?php

namespace Plugins\G7\Image\Delivery\Console\Commands;

use Illuminate\Console\Command;
use Plugins\G7\Image\Delivery\Services\VariantBuilder;
use Plugins\G7\Image\Delivery\Support\VariantPath;
use Plugins\G7\Image\Delivery\Support\VariantPlan;

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
        {--scheduled : 스케줄러 호출 (설정이 꺼져 있으면 아무것도 하지 않는다)}
        {--backfill-width= : 이 폭이 빠진 기존 원본만 채운다 (0.2.0, 일회성 보정)}';

    protected $description = '본문 이미지의 중간 크기 변환본을 생성합니다 (미생성분부터)';

    public function handle(VariantBuilder $builder): int
    {
        if ($this->option('scheduled') && ! plugin_setting(VariantPath::IDENTIFIER, 'variants_enabled', true)) {
            $this->info('변환본 생성이 설정에서 꺼져 있어 건너뜁니다.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $backfillWidth = $this->option('backfill-width');

        if ($backfillWidth !== null && $backfillWidth !== '') {
            return $this->runBackfill($builder, (int) $backfillWidth, $limit, $dryRun);
        }

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
        $this->line(sprintf(
            '  표식으로 제외된 원본 %d건%s',
            $result['already_marked'],
            $result['newly_marked'] > 0
                ? sprintf(' (이번에 %s %d건)', $dryRun ? '표식 예정' : '표식 추가', $result['newly_marked'])
                : ''
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 특정 폭 백필 실행 (0.2.0).
     *
     * 정규 경로(`buildPending`)는 원본 단위로 "볼 일이 남았는가" 를 판정해, 이미 다른 폭을
     * 만들어 둔 기존 원본을 영영 집지 않는다. 폭을 새로 더한 뒤 기존 원본을 채우는 것은
     * 이 경로로만 한다. 한 번 채우고 나면 다시 쓸 일이 없다.
     */
    private function runBackfill(VariantBuilder $builder, int $width, int $limit, bool $dryRun): int
    {
        if (! in_array($width, VariantPlan::BUILD_WIDTHS, true)) {
            $this->error(sprintf(
                '--backfill-width 는 생성 대상 폭이어야 합니다: %s (받은 값: %d)',
                implode(', ', VariantPlan::BUILD_WIDTHS),
                $width
            ));

            return self::FAILURE;
        }

        // 진행 막대는 첫 콜백에서 총량을 받아 만든다 — 대상 수는 builder 가 센다.
        $bar = null;
        $result = $builder->backfillWidth(
            $width,
            $limit,
            $dryRun,
            function (string $hash, string $status, int $done, int $total) use (&$bar) {
                if ($bar === null) {
                    $bar = $this->output->createProgressBar($total);
                    $bar->start();
                }

                $bar->setProgress($done);
            }
        );

        if ($bar !== null) {
            $bar->finish();
            $this->newLine(2);
        }

        if (($result['items'][0]['status'] ?? null) === 'aborted') {
            $this->error('imagick 을 쓸 수 없어 중단했습니다.');

            return self::FAILURE;
        }

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
            '%s[백필 %dw] 대상 %d · 검사 %d · 생성 %d · 건너뜀 %d · 실패 %d',
            $dryRun ? '[모의] ' : '',
            $width,
            $result['eligible'],
            $result['scanned'],
            $result['built'],
            $result['skipped'],
            $result['failed']
        ));

        $remaining = $result['eligible'] - $result['scanned'];
        if ($remaining > 0) {
            $this->line(sprintf('  남은 대상 %d건 — 같은 명령을 다시 실행하거나 --limit 을 올리세요.', $remaining));
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
