<?php

namespace Plugins\G7\Image\Delivery\Console\Commands;

use Illuminate\Console\Command;
use Plugins\G7\Image\Delivery\Services\VariantBuilder;
use Plugins\G7\Image\Delivery\Support\VariantPath;

/**
 * 원본 행이 사라진 변환본을 지운다.
 *
 * `sirsoft-ckeditor5` 가 삭제 훅을 내보내지 않으므로(업로드 훅 3종뿐) 실시간 연동이
 * 불가능하다. 공개 경로가 요청 시점에 원본 존재를 확인해 **보이지 않게** 만들고,
 * 디스크 회수는 이 명령이 매일 맡는다.
 */
class PruneVariantsCommand extends Command
{
    protected $signature = 'g7-image-delivery:prune-variants
        {--dry-run : 지우지 않고 대상만 출력한다}
        {--scheduled : 스케줄러 호출 (설정이 꺼져 있으면 아무것도 하지 않는다)}';

    protected $description = '원본이 삭제된 변환본을 정리합니다';

    public function handle(VariantBuilder $builder): int
    {
        if ($this->option('scheduled') && ! plugin_setting(VariantPath::IDENTIFIER, 'variants_enabled', true)) {
            $this->info('변환본 기능이 설정에서 꺼져 있어 건너뜁니다.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = $builder->pruneOrphans($dryRun);

        foreach ($result['items'] as $item) {
            $this->line(sprintf('  %-12s %-6s %s', $item['hash'], $item['width'], $item['status']));
        }

        $this->newLine();
        $this->info(sprintf(
            '%s검사 %d · %s %d',
            $dryRun ? '[모의] ' : '',
            $result['scanned'],
            $dryRun ? '삭제 예정' : '삭제',
            $dryRun ? count($result['items']) : $result['deleted']
        ));

        return self::SUCCESS;
    }
}
