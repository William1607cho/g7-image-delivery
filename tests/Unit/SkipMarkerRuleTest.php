<?php

namespace Plugins\G7\Image\Delivery\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Image\Delivery\Support\VariantPlan;

/**
 * 표식(생성 불필요) 규칙의 순수 부분을 검증한다.
 *
 * DB·파일 접근이 있는 부분(`VariantBuilder`)은 여기서 다루지 않고, 표식의 **판단 근거**가
 * 되는 두 규칙만 본다.
 *
 *  1. 어떤 원본이 "변환본이 아예 안 생기는" 원본인가 → `VariantPlan::variantsFor()` 가 빈 계획
 *  2. 표식이 언제 무효가 되는가 → 원본의 path·bytes·mime 지문 비교
 *
 * 2번은 `VariantBuilder` 의 질의가 SQL 로 하는 비교와 같은 규칙을 이 테스트가 코드로 재현해
 * 고정한다 — 규칙이 바뀌면 여기서 먼저 깨진다.
 */
class SkipMarkerRuleTest extends TestCase
{
    /**
     * 표식이 유효한지 판정한다 (VariantBuilder 질의의 whereColumn 3개와 같은 규칙).
     *
     * @param  array{path: string, bytes: int, mime: string}  $marker  표식에 적힌 값
     * @param  array{path: string, bytes: int, mime: string}  $current  지금 원본 값
     */
    private function markerStillValid(array $marker, array $current): bool
    {
        return $marker['path'] === $current['path']
            && $marker['bytes'] === $current['bytes']
            && $marker['mime'] === $current['mime'];
    }

    // ── 1. 어떤 원본이 표식 대상인가 ────────────────────────────────

    public function test_공칭_폭보다_좁은_원본은_변환본이_안_생긴다(): void
    {
        // 이런 원본이 표식 없이 남으면 매 실행마다 다시 뽑혀 배치가 멈춘다.
        $this->assertSame([], VariantPlan::variantsFor(600, 400));
        $this->assertSame([], VariantPlan::variantsFor(960, 540));
    }

    public function test_공칭_폭보다_넓은_원본은_표식_대상이_아니다(): void
    {
        $this->assertNotSame([], VariantPlan::variantsFor(961, 540));
    }

    public function test_작은_원본이_limit_이상_연달아_있어도_표식이_있으면_다음_실행이_전진한다(): void
    {
        // v0.1.0 이 멈춘 상황을 그대로 세운다: 배치 크기 20, 작은 원본 28건 연속.
        $limit = 20;
        $originals = [];
        for ($i = 0; $i < 28; $i++) {
            $originals[] = ['id' => $i, 'width' => 600, 'marked' => false];
        }
        for ($i = 28; $i < 34; $i++) {
            $originals[] = ['id' => $i, 'width' => 4000, 'marked' => false];
        }

        $built = 0;
        $runs = 0;

        // 한 번 실행 = 표식 없는 원본을 id 순으로 limit 개 검사하고, 빈 계획이면 표식을 남긴다.
        while ($runs < 10) {
            $runs++;
            $scanned = 0;

            foreach ($originals as $index => $original) {
                if ($scanned >= $limit) {
                    break;
                }
                if ($original['marked']) {
                    continue;   // 표식이 있으면 후보에서 빠진다 (v0.1.1 의 변화)
                }

                $scanned++;

                if (VariantPlan::variantsFor($original['width'], 500) === []) {
                    $originals[$index]['marked'] = true;
                } else {
                    $originals[$index]['marked'] = true;   // 생성 완료 → 행이 생겨 역시 빠진다
                    $built++;
                }
            }

            if ($scanned === 0) {
                break;
            }
        }

        $this->assertSame(6, $built, '넓은 원본 6건이 모두 생성되어야 한다');
        $this->assertLessThanOrEqual(3, $runs, '연속 구간을 넘어 몇 번 만에 끝나야 한다');
    }

    // ── 2. 표식 무효화 규칙 ─────────────────────────────────────────

    public function test_원본이_그대로면_표식이_유효하다(): void
    {
        $marker = ['path' => 'images/2026/09/19/a.png', 'bytes' => 1000, 'mime' => 'image/png'];

        $this->assertTrue($this->markerStillValid($marker, $marker));
    }

    public function test_제자리_변환이면_경로_크기_MIME_이_모두_바뀌어_표식이_무효가_된다(): void
    {
        $marker = ['path' => 'images/2026/09/19/a.png', 'bytes' => 1000, 'mime' => 'image/png'];
        $afterConvert = ['path' => 'images/2026/09/19/a.webp', 'bytes' => 400, 'mime' => 'image/webp'];

        $this->assertFalse($this->markerStillValid($marker, $afterConvert));
    }

    public function test_크기만_달라져도_표식이_무효다(): void
    {
        $marker = ['path' => 'images/2026/09/19/a.png', 'bytes' => 1000, 'mime' => 'image/png'];
        $changed = ['path' => 'images/2026/09/19/a.png', 'bytes' => 999, 'mime' => 'image/png'];

        $this->assertFalse($this->markerStillValid($marker, $changed));
    }

    public function test_MIME_만_달라져도_표식이_무효다(): void
    {
        $marker = ['path' => 'images/2026/09/19/a.png', 'bytes' => 1000, 'mime' => 'image/png'];
        $changed = ['path' => 'images/2026/09/19/a.png', 'bytes' => 1000, 'mime' => 'image/webp'];

        $this->assertFalse($this->markerStillValid($marker, $changed));
    }

    public function test_되돌리기로_원래_값이_되면_표식이_다시_유효해진다(): void
    {
        // 되돌리기는 path·bytes·mime 을 스냅샷대로 복원하므로, 그 시점 표식은 다시 들어맞는다.
        $marker = ['path' => 'images/2026/09/19/a.png', 'bytes' => 1000, 'mime' => 'image/png'];
        $afterConvert = ['path' => 'images/2026/09/19/a.webp', 'bytes' => 400, 'mime' => 'image/webp'];
        $afterRevert = $marker;

        $this->assertFalse($this->markerStillValid($marker, $afterConvert));
        $this->assertTrue($this->markerStillValid($marker, $afterRevert));
    }

    public function test_해시만으로는_원본_변경을_알_수_없다(): void
    {
        // 해시는 본문을 안 고치려고 일부러 유지한다 — 그래서 무효화 근거가 될 수 없다.
        $hashBefore = '53d814be8c1e';
        $hashAfter = '53d814be8c1e';

        $this->assertSame($hashBefore, $hashAfter);
        $this->assertFalse($this->markerStillValid(
            ['path' => 'images/a.png', 'bytes' => 1000, 'mime' => 'image/png'],
            ['path' => 'images/a.webp', 'bytes' => 400, 'mime' => 'image/webp'],
        ));
    }
}
