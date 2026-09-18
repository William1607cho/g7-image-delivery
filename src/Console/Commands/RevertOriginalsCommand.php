<?php

namespace Plugins\G7\Image\Delivery\Console\Commands;

use Illuminate\Console\Command;
use Plugins\G7\Image\Delivery\Services\OriginalConverter;

/**
 * 제자리 변환을 배치 단위로 되돌린다.
 *
 * 배치 폴더의 `manifest.json` 과 백업 파일을 근거로 원래 파일·DB 행을 복원한다.
 */
class RevertOriginalsCommand extends Command
{
    protected $signature = 'g7-image-delivery:revert-originals
        {--batch= : 되돌릴 배치 id (생략하면 목록만 출력)}
        {--dry-run : 되돌리지 않고 대상만 출력한다}';

    protected $description = '제자리 변환을 배치 단위로 되돌립니다';

    public function handle(OriginalConverter $converter): int
    {
        $batch = (string) ($this->option('batch') ?? '');

        if ($batch === '') {
            $batches = $converter->listBatches();

            if ($batches === []) {
                $this->info('되돌릴 수 있는 배치가 없습니다.');

                return self::SUCCESS;
            }

            $this->info('되돌릴 수 있는 배치:');
            foreach ($batches as $candidate) {
                $this->line('  '.$candidate);
            }
            $this->newLine();
            $this->comment('--batch=<id> 로 대상을 지정하세요.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = $converter->revert($batch, $dryRun);

        foreach ($result['items'] as $item) {
            $this->line(sprintf(
                '  %-12s %-14s %s',
                $item['hash'] ?? '-',
                $item['status'] ?? '-',
                $item['reason'] ?? ''
            ));
        }

        $this->newLine();
        $this->info(sprintf(
            '%s배치 %s · 검사 %d · 복원 %d · 실패 %d',
            $dryRun ? '[모의] ' : '',
            $result['batch'],
            $result['scanned'],
            $result['reverted'],
            $result['failed']
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
