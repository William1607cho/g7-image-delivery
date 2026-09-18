<?php

namespace Plugins\G7\Image\Delivery\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Image\Delivery\Support\VariantPlan;

/**
 * 변환본 계획 계산 — 프레임워크에 기대지 않는 순수 단위 테스트.
 */
class VariantPlanTest extends TestCase
{
    public function test_큰_원본은_두_벌_다_만든다(): void
    {
        $plans = VariantPlan::variantsFor(4000, 2250);

        $this->assertCount(2, $plans);
        $this->assertSame(960, $plans[0]['out_width']);
        $this->assertSame(540, $plans[0]['out_height']);
        $this->assertSame('webp', $plans[0]['format']);
        $this->assertSame(1600, $plans[1]['out_width']);
        $this->assertSame(900, $plans[1]['out_height']);
    }

    public function test_원본보다_키우지_않는다(): void
    {
        // 1200 폭이면 960 만 만든다 — 1600 은 업스케일이 되므로 만들지 않는다.
        $plans = VariantPlan::variantsFor(1200, 800);

        $this->assertCount(1, $plans);
        $this->assertSame(960, $plans[0]['out_width']);
    }

    public function test_공칭_폭과_같으면_만들지_않는다(): void
    {
        $this->assertSame([], VariantPlan::variantsFor(960, 540));
    }

    public function test_960_미만_원본은_변환본이_없다(): void
    {
        $this->assertSame([], VariantPlan::variantsFor(600, 400));
    }

    public function test_긴_이미지는_세로가_비율대로_따라간다(): void
    {
        $plans = VariantPlan::variantsFor(1080, 9000);

        $this->assertCount(1, $plans);
        $this->assertSame(960, $plans[0]['out_width']);
        $this->assertSame(8000, $plans[0]['out_height']);
        $this->assertSame('webp', $plans[0]['format']);
    }

    public function test_줄인_뒤에도_세로가_한계를_넘으면_jpeg_로_만든다(): void
    {
        // 2000x35000 → 960 폭이면 세로 16800 > 16383
        $plans = VariantPlan::variantsFor(2000, 35000);

        $this->assertSame('jpg', $plans[0]['format']);
        $this->assertGreaterThan(VariantPlan::WEBP_MAX_DIMENSION, $plans[0]['out_height']);
    }

    public function test_줄인_뒤_한계_이내면_webp_다(): void
    {
        // 2000x32000 → 960 폭이면 세로 15360 ≤ 16383
        $plans = VariantPlan::variantsFor(2000, 32000);

        $this->assertSame('webp', $plans[0]['format']);
    }

    public function test_잘못된_치수는_빈_계획이다(): void
    {
        $this->assertSame([], VariantPlan::variantsFor(0, 100));
        $this->assertSame([], VariantPlan::variantsFor(100, 0));
        $this->assertSame([], VariantPlan::variantsFor(-1, -1));
    }

    public function test_세로는_최소_1_이다(): void
    {
        // 극단적으로 납작한 이미지도 0 이 되면 안 된다.
        $plans = VariantPlan::variantsFor(100000, 1);

        $this->assertGreaterThanOrEqual(1, $plans[0]['out_height']);
    }

    // ── 원본 srcset 후보 규칙 ──────────────────────────────────────────

    public function test_1600_변환본이_없고_webp_면_원본이_후보가_된다(): void
    {
        $this->assertSame(1200, VariantPlan::originalCandidateWidth(1200, 'image/webp'));
    }

    public function test_1600_변환본이_없고_jpeg_면_원본이_후보가_된다(): void
    {
        $this->assertSame(1500, VariantPlan::originalCandidateWidth(1500, 'image/jpeg'));
    }

    public function test_png_원본은_후보가_아니다(): void
    {
        // 제자리 변환 전 PNG 는 수십 MB 일 수 있어 고밀도 화면에 내려보내면 안 된다.
        $this->assertNull(VariantPlan::originalCandidateWidth(1200, 'image/png'));
    }

    public function test_1600_변환본이_생기면_원본은_후보가_아니다(): void
    {
        $this->assertNull(VariantPlan::originalCandidateWidth(2400, 'image/webp'));
        $this->assertNull(VariantPlan::originalCandidateWidth(1601, 'image/jpeg'));
    }

    public function test_경계값_1600_은_후보다(): void
    {
        // 가로가 정확히 1600 이면 1600 변환본이 생기지 않으므로 후보가 맞다.
        $this->assertSame(1600, VariantPlan::originalCandidateWidth(1600, 'image/webp'));
    }

    public function test_gif_원본은_후보가_아니다(): void
    {
        $this->assertNull(VariantPlan::originalCandidateWidth(1200, 'image/gif'));
    }

    // ── 제자리 변환 대상 판정 ──────────────────────────────────────────

    public function test_png_와_jpeg_는_제자리_변환_대상이다(): void
    {
        $this->assertNull(VariantPlan::inPlaceSkipReason('image/png', 1000, 800));
        $this->assertNull(VariantPlan::inPlaceSkipReason('image/jpeg', 1000, 800));
    }

    public function test_이미_webp_면_건너뛴다(): void
    {
        $this->assertSame('already_webp', VariantPlan::inPlaceSkipReason('image/webp', 1000, 800));
    }

    public function test_gif_와_svg_는_대상이_아니다(): void
    {
        $this->assertSame('unsupported_mime', VariantPlan::inPlaceSkipReason('image/gif', 100, 100));
        $this->assertSame('unsupported_mime', VariantPlan::inPlaceSkipReason('image/svg+xml', 100, 100));
    }

    public function test_webp_치수_한계를_넘으면_제자리_변환하지_않는다(): void
    {
        // 치수를 바꾸지 않는 변환이라 한계를 넘으면 담을 그릇이 없다.
        $this->assertSame('exceeds_webp_dimension', VariantPlan::inPlaceSkipReason('image/png', 20000, 100));
        $this->assertSame('exceeds_webp_dimension', VariantPlan::inPlaceSkipReason('image/png', 100, 20000));
        $this->assertNull(VariantPlan::inPlaceSkipReason('image/png', 16383, 16383));
    }

    public function test_치수를_읽지_못하면_건너뛴다(): void
    {
        $this->assertSame('unreadable_dimensions', VariantPlan::inPlaceSkipReason('image/png', 0, 0));
    }
}
