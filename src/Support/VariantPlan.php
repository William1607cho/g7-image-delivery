<?php

namespace Plugins\G7\Image\Delivery\Support;

/**
 * 원본 치수로부터 "어떤 변환본을 만들 것인가" 를 계산하는 순수 클래스.
 *
 * 파일도 DB 도 건드리지 않는다 — 입력은 원본 가로·세로·MIME 뿐이고 출력은 계획 배열이다.
 * 이 계산만 따로 떼어낸 이유는 규칙이 자잘하고(업스케일 금지·WebP 치수 한계·원본 후보
 * 조건) 실수하면 조용히 틀린 이미지를 만들기 때문이다. 단위 테스트 대상.
 */
final class VariantPlan
{
    /**
     * 공칭 폭 목록.
     *
     * 근거(작업 2-1 F1 실측): 본문 실폭은 데스크톱 848 CSS px
     * (`max-w-4xl` 896 − 본문 카드 `p-6` 좌우 48), 모바일은 뷰포트 − 80 px
     * (`px-4` 32 + `p-6` 48). 960 이 데스크톱 DPR1·모바일 DPR3 를, 1600 이
     * 데스크톱 DPR2(1696 에 6% 못 미치나 용량 대비 타당)를 덮는다.
     */
    public const NOMINAL_WIDTHS = [960, 1600];

    /**
     * WebP 컨테이너가 표현할 수 있는 최대 가로·세로 (픽셀).
     *
     * 이 값을 넘는 변환본은 WebP 로 인코딩할 수 없어 JPEG 로 만든다.
     */
    public const WEBP_MAX_DIMENSION = 16383;

    /**
     * `sizes` 속성 값. 위 공칭 폭과 같은 실측 근거에서 나온다.
     */
    public const SIZES = '(min-width: 960px) 848px, calc(100vw - 80px)';

    /**
     * 원본 후보를 srcset 에 넣을 수 있는 MIME 목록.
     *
     * PNG 는 제외한다 — 제자리 변환 전 PNG 원본은 수 MB~수십 MB 일 수 있어(blog 실측:
     * PNG 19건 평균 20MB) 후보로 올리면 고밀도 화면에서 그걸 내려받게 된다.
     */
    public const ORIGINAL_CANDIDATE_MIMES = ['image/webp', 'image/jpeg'];

    /**
     * 원본 치수에 대해 생성할 변환본 목록을 계산합니다.
     *
     * 규칙:
     *  - 공칭 폭 W 는 **원본 가로가 W 보다 클 때만** 만든다 (업스케일 금지)
     *  - 세로는 가로 축소 비율 그대로 (반올림, 최소 1)
     *  - 축소 후 세로가 WebP 한계를 넘으면 그 변환본만 JPEG 로 만든다
     *
     * @param  int  $srcWidth  원본 가로
     * @param  int  $srcHeight  원본 세로
     * @return list<array{width: int, out_width: int, out_height: int, format: string}>
     */
    public static function variantsFor(int $srcWidth, int $srcHeight): array
    {
        if ($srcWidth <= 0 || $srcHeight <= 0) {
            return [];
        }

        $plans = [];

        foreach (self::NOMINAL_WIDTHS as $nominal) {
            if ($srcWidth <= $nominal) {
                continue;
            }

            $outHeight = max(1, (int) round($srcHeight * $nominal / $srcWidth));

            $plans[] = [
                'width' => $nominal,
                'out_width' => $nominal,
                'out_height' => $outHeight,
                'format' => $outHeight > self::WEBP_MAX_DIMENSION ? 'jpg' : 'webp',
            ];
        }

        return $plans;
    }

    /**
     * 원본을 srcset 후보로 넣을지 판정합니다.
     *
     * 1600 변환본이 생기지 않는 원본(가로 ≤ 1600)만 후보가 된다 — 그 경우 srcset 최대
     * 후보가 960w 라, 고밀도 데스크톱(848 CSS px × DPR2 = 1696)이 960 짜리를 늘려
     * 그리게 되어 **가공 전보다 흐려지기 때문**이다. 원본을 한 줄 더 얹으면 저장 비용
     * 없이 그 손해만 없앤다.
     *
     * PNG 원본은 넣지 않는다(위 ORIGINAL_CANDIDATE_MIMES 주석 참고).
     *
     * @param  int  $srcWidth  원본 가로
     * @param  string  $mimeType  원본 MIME
     * @return int|null srcset 에 넣을 폭 서술자 값, 넣지 않으면 null
     */
    public static function originalCandidateWidth(int $srcWidth, string $mimeType): ?int
    {
        if ($srcWidth <= 0) {
            return null;
        }

        // 1600 변환본이 생기면(= 원본이 1600 보다 크면) 원본 후보는 불필요하다.
        if ($srcWidth > self::NOMINAL_WIDTHS[count(self::NOMINAL_WIDTHS) - 1]) {
            return null;
        }

        if (! in_array(strtolower($mimeType), self::ORIGINAL_CANDIDATE_MIMES, true)) {
            return null;
        }

        return $srcWidth;
    }

    /**
     * 제자리 변환(원본을 WebP 로) 대상인지 판정합니다.
     *
     * @param  string  $mimeType  원본 MIME
     * @param  int  $srcWidth  원본 가로
     * @param  int  $srcHeight  원본 세로
     * @return string|null 대상이 아니면 그 사유, 대상이면 null
     */
    public static function inPlaceSkipReason(string $mimeType, int $srcWidth, int $srcHeight): ?string
    {
        $mime = strtolower($mimeType);

        if ($mime === 'image/webp') {
            return 'already_webp';
        }

        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            // GIF(애니메이션 파괴)·SVG(벡터)·그 밖의 형식은 대상이 아니다.
            return 'unsupported_mime';
        }

        if ($srcWidth <= 0 || $srcHeight <= 0) {
            return 'unreadable_dimensions';
        }

        if ($srcWidth > self::WEBP_MAX_DIMENSION || $srcHeight > self::WEBP_MAX_DIMENSION) {
            // 치수를 바꾸지 않는 변환이므로 한계를 넘으면 WebP 로 담을 수가 없다.
            return 'exceeds_webp_dimension';
        }

        return null;
    }
}
