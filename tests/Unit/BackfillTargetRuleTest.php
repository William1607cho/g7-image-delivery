<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Image\Delivery\Support\VariantPlan;

/**
 * 백필 대상 판정 규칙 테스트 (0.2.0).
 *
 * 실제 질의는 DB 를 타므로 여기서는 **판정의 뼈대**를 같은 규칙으로 재현해 고정한다.
 * 규칙이 바뀌면 이 테스트가 먼저 깨지도록 두는 것이 목적이다.
 *
 * 대상 조건 (VariantBuilder::backfillCandidates 와 같은 순서):
 *  1. GIF 가 아니다
 *  2. 그 폭의 ready 행이 없다
 *  3. 원본 가로가 그 폭보다 크다 (확대 금지)
 *  4. 표식이 있다면 폭에 따라 결론이 달라지는 사유여야 한다
 */
class BackfillTargetRuleTest extends TestCase
{
    /** 폭에 따라 결론이 달라지는 표식 사유 — VariantBuilder 의 같은 이름 상수와 맞춘다. */
    private const WIDTH_DEPENDENT = ['no_downscale_needed'];

    /**
     * @param  array{mime: string, src_width: int, ready_widths: list<int>, skip_reason: ?string}  $upload
     */
    private function isTarget(array $upload, int $width): bool
    {
        if ($upload['mime'] === 'image/gif') {
            return false;                                             // (1)
        }

        if (in_array($width, $upload['ready_widths'], true)) {
            return false;                                             // (2)
        }

        if ($upload['src_width'] <= $width) {
            return false;                                             // (3)
        }

        if ($upload['skip_reason'] !== null
            && ! in_array($upload['skip_reason'], self::WIDTH_DEPENDENT, true)) {
            return false;                                             // (4)
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{mime: string, src_width: int, ready_widths: list<int>, skip_reason: ?string}
     */
    private function upload(array $overrides = []): array
    {
        return $overrides + ['mime' => 'image/webp', 'src_width' => 1280, 'ready_widths' => [960], 'skip_reason' => null];
    }

    public function test_960만_있는_원본은_240_백필_대상이다(): void
    {
        $this->assertTrue($this->isTarget($this->upload(), VariantPlan::THUMB_WIDTH));
    }

    public function test_이미_240이_있으면_대상이_아니다(): void
    {
        $this->assertFalse($this->isTarget(
            $this->upload(['ready_widths' => [240, 960]]),
            VariantPlan::THUMB_WIDTH
        ));
    }

    public function test_가로가_240_이하면_대상이_아니다(): void
    {
        $this->assertFalse($this->isTarget(
            $this->upload(['src_width' => 240, 'ready_widths' => []]),
            VariantPlan::THUMB_WIDTH
        ));
        $this->assertFalse($this->isTarget(
            $this->upload(['src_width' => 200, 'ready_widths' => []]),
            VariantPlan::THUMB_WIDTH
        ));
    }

    public function test_가로_241이면_대상이다(): void
    {
        $this->assertTrue($this->isTarget(
            $this->upload(['src_width' => 241, 'ready_widths' => []]),
            VariantPlan::THUMB_WIDTH
        ));
    }

    public function test_불필요_표식은_240_기준으로_다시_판정한다(): void
    {
        // 800px 원본 — 960 기준으로는 불필요했지만 240 은 만들어야 한다.
        $this->assertTrue($this->isTarget(
            $this->upload(['src_width' => 800, 'ready_widths' => [], 'skip_reason' => 'no_downscale_needed']),
            VariantPlan::THUMB_WIDTH
        ));
    }

    public function test_생성_불가_표식은_대상에서_제외한다(): void
    {
        foreach (['source_pixel_cap', 'gif_not_targeted'] as $reason) {
            $this->assertFalse(
                $this->isTarget(
                    $this->upload(['src_width' => 4000, 'ready_widths' => [], 'skip_reason' => $reason]),
                    VariantPlan::THUMB_WIDTH
                ),
                "표식 사유 {$reason} 은 폭과 무관하게 불가이므로 제외돼야 한다"
            );
        }
    }

    public function test_gif_는_대상이_아니다(): void
    {
        $this->assertFalse($this->isTarget(
            $this->upload(['mime' => 'image/gif', 'src_width' => 1280, 'ready_widths' => []]),
            VariantPlan::THUMB_WIDTH
        ));
    }

    public function test_960_백필로도_같은_규칙이_동작한다(): void
    {
        // 폭 인자를 바꿔도 규칙이 그대로 성립해야 한다(240 전용 규칙이 아니다).
        $this->assertTrue($this->isTarget(
            $this->upload(['src_width' => 1280, 'ready_widths' => [240]]),
            VariantPlan::BODY_BASE_WIDTH
        ));
        $this->assertFalse($this->isTarget(
            $this->upload(['src_width' => 800, 'ready_widths' => [240]]),
            VariantPlan::BODY_BASE_WIDTH
        ));
    }
}
