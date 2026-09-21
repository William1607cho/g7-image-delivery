<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Image\Delivery\Support\VariantPlan;

/**
 * 폭 상수의 **의미**가 서로 섞이지 않는지 지키는 테스트 (0.2.0).
 *
 * 0.2.0 이전에는 `NOMINAL_WIDTHS[0]` 을 "본문 src 기준 폭" 으로, 마지막 원소를 "최대 폭"
 * 으로 꺼내 썼다. 목록용 240 을 그 배열에 더하는 순간 본문 이미지가 240px 로 바뀌는
 * 구조였다. 아래 테스트는 그 사고 경로가 되살아나지 않는지 본다.
 */
class ThumbWidthPlanTest extends TestCase
{
    public function test_본문_기준폭은_목록_썸네일_폭과_다르다(): void
    {
        $this->assertNotSame(VariantPlan::THUMB_WIDTH, VariantPlan::BODY_BASE_WIDTH);
        $this->assertSame(960, VariantPlan::BODY_BASE_WIDTH);
        $this->assertSame(240, VariantPlan::THUMB_WIDTH);
    }

    public function test_본문_srcset_에는_목록_썸네일_폭이_없다(): void
    {
        $this->assertNotContains(VariantPlan::THUMB_WIDTH, VariantPlan::BODY_SRCSET_WIDTHS);
        $this->assertSame([960, 1600], VariantPlan::BODY_SRCSET_WIDTHS);
    }

    public function test_최대폭은_생성폭의_인덱스가_아니라_고정값이다(): void
    {
        // BUILD_WIDTHS 에 더 작은 폭이 앞에 붙어도 최대폭은 흔들리지 않아야 한다.
        $this->assertSame(1600, VariantPlan::BODY_MAX_WIDTH);
        $this->assertSame(max(VariantPlan::BUILD_WIDTHS), VariantPlan::BODY_MAX_WIDTH);
    }

    public function test_생성폭은_오름차순이고_본문폭을_모두_포함한다(): void
    {
        $widths = VariantPlan::BUILD_WIDTHS;
        $sorted = $widths;
        sort($sorted);

        $this->assertSame($sorted, $widths, 'BUILD_WIDTHS 는 오름차순이어야 한다');

        foreach (VariantPlan::BODY_SRCSET_WIDTHS as $w) {
            $this->assertContains($w, $widths);
        }

        $this->assertContains(VariantPlan::THUMB_WIDTH, $widths);
    }

    public function test_2000px_원본은_세_폭을_모두_만든다(): void
    {
        $plans = VariantPlan::variantsFor(2000, 1330);

        $this->assertSame([240, 960, 1600], array_column($plans, 'width'));
        $this->assertSame(160, $plans[0]['out_height']);   // 1330 * 240 / 2000
        $this->assertSame('webp', $plans[0]['format']);
    }

    public function test_가로_240_이하_원본은_240을_만들지_않는다(): void
    {
        // 확대 금지 — 240 이하는 계획이 비어야 한다.
        $this->assertSame([], VariantPlan::variantsFor(240, 240));
        $this->assertSame([], VariantPlan::variantsFor(200, 300));
    }

    public function test_가로_241_원본은_240만_만든다(): void
    {
        $plans = VariantPlan::variantsFor(241, 241);

        $this->assertSame([240], array_column($plans, 'width'));
    }

    public function test_800px_원본은_240만_만든다(): void
    {
        // 0.1.1 에서는 계획이 비어 no_downscale_needed 표식이 붙던 크기다.
        $plans = VariantPlan::variantsFor(800, 483);

        $this->assertSame([240], array_column($plans, 'width'));
        $this->assertSame(145, $plans[0]['out_height']);   // 483 * 240 / 800
    }

    public function test_원본_후보_판정은_최대폭_기준을_유지한다(): void
    {
        // 240 이 생성폭에 들어와도 원본 후보 판정 기준은 1600 그대로여야 한다.
        $this->assertSame(1200, VariantPlan::originalCandidateWidth(1200, 'image/webp'));
        $this->assertNull(VariantPlan::originalCandidateWidth(2000, 'image/webp'));
        $this->assertNull(VariantPlan::originalCandidateWidth(1200, 'image/png'));
    }
}
