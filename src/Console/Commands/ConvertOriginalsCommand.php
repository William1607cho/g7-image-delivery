<?php

namespace Plugins\G7\Image\Delivery\Console\Commands;

use Illuminate\Console\Command;
use Plugins\G7\Image\Delivery\Services\OriginalConverter;

/**
 * 에디터 업로드 원본을 제자리에서 WebP 로 바꾼다 (치수 불변, 해시 불변).
 *
 * **기본이 모의 실행이다.** 실제로 파일을 바꾸려면 `--apply` 를 명시해야 한다 —
 * 되돌리기가 있어도 되돌릴 일을 안 만드는 쪽이 낫다.
 */
class ConvertOriginalsCommand extends Command
{
    protected $signature = 'g7-image-delivery:convert-originals
        {--dry-run : 바꾸지 않고 대상만 출력한다 (기본 동작)}
        {--apply : 실제로 변환한다 (이 옵션이 없으면 모의 실행)}
        {--limit=50 : 이번 회차에 처리할 원본 수}';

    protected $description = '에디터 업로드 원본(PNG·JPEG)을 제자리에서 WebP 로 변환합니다';

    public function handle(OriginalConverter $converter): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = ! $apply;

        if ($this->option('dry-run') && $apply) {
            $this->error('--dry-run 과 --apply 를 함께 줄 수 없습니다.');

            return self::INVALID;
        }

        $limit = max(1, (int) $this->option('limit'));
        $result = $converter->convert($limit, $dryRun);

        foreach ($result['items'] as $item) {
            $this->line(sprintf(
                '  %-12s %-14s %-22s %s',
                $item['hash'] ?? '-',
                $item['status'] ?? '-',
                $item['src'] ?? '',
                $item['reason'] ?? (isset($item['saved']) ? '절감 '.$this->humanBytes((int) $item['saved']) : '')
            ));
        }

        $this->newLine();
        $this->info(sprintf(
            '%s검사 %d · 변환 %d · 건너뜀 %d · 실패 %d · 절감 %s',
            $dryRun ? '[모의] ' : '',
            $result['scanned'],
            $result['converted'],
            $result['skipped'],
            $result['failed'],
            $this->humanBytes($result['saved_bytes'])
        ));

        if ($dryRun) {
            $this->comment('실제로 변환하려면 --apply 를 붙여 다시 실행하세요.');
        } elseif ($result['batch'] !== null) {
            $this->newLine();
            $this->info('백업 배치: '.$result['batch']);
            $this->line('  저장 위치: storage/app/plugins/g7-image-delivery/backups/'.$result['batch'].'/');
            $this->line('  되돌리기: php artisan g7-image-delivery:revert-originals --batch='.$result['batch']);
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }
}
