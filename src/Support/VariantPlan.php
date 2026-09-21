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
     * 본문 `<img src>` 가 가리키는 기준 폭.
     *
     * 근거(작업 2-1 F1 실측): 본문 실폭은 데스크톱 848 CSS px
     * (`max-w-4xl` 896 − 본문 카드 `p-6` 좌우 48), 모바일은 뷰포트 − 80 px
     * (`px-4` 32 + `p-6` 48). 960 이 데스크톱 DPR1·모바일 DPR3 를 덮는다.
     *
     * **폭 목록의 첫 원소로 이 값을 꺼내 쓰지 않는다.** 0.2.0 에서 목록 썸네일용
     * 240 을 생성 폭에 더하면서, 인덱스로 의미를 꺼내던 코드가 조용히 240 을
     * 본문 기준으로 잡는 사고 경로가 생겼다. 의미마다 상수를 따로 둔다.
     */
    public const BODY_BASE_WIDTH = 960;

    /**
     * 본문 `srcset` 후보로 내보내는 폭 목록.
     *
     * 1600 이 데스크톱 DPR2(1696 에 6% 못 미치나 용량 대비 타당)를 덮는다.
     * **목록 썸네일 폭(240)은 여기에 넣지 않는다** — 본문 표시폭이 848 CSS px 라
     * 240 후보는 쓸모가 없고, 브라우저가 고를 여지만 만든다.
     */
    public const BODY_SRCSET_WIDTHS = [960, 1600];

    /**
     * 생성하는 폭 중 가장 큰 값 (원본 srcset 후보 판정 기준).
     *
     * {@see self::originalCandidateWidth()} 가 "이 폭보다 큰 원본은 변환본이 생기므로
     * 원본 후보가 불필요하다" 를 판정할 때 쓴다.
     */
    public const BODY_MAX_WIDTH = 1600;

    /**
     * 목록 썸네일 전용 폭 (0.2.0 신설).
     *
     * 근거: 게시판 목록의 정사각 썸네일은 `w-20 h-20` = 80×80 CSS px 이다.
     * 240 이 DPR3 까지 덮는다. 본문 경로에서는 이 폭을 쓰지 않는다.
     */
    public const THUMB_WIDTH = 240;

    /**
     * 실제로 생성할 폭 전체 (오름차순).
     *
     * 생성 계획({@see self::variantsFor()})과 공개 서빙 허용 목록
     * ({@see \Plugins\G7\Image\Delivery\Http\Controllers\VariantServeController})만
     * 이 목록을 쓴다. **본문 렌더링 경로는 이 상수를 읽지 않는다.**
     */
    public const BUILD_WIDTHS = [self::THUMB_WIDTH, 960, 1600];

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

        foreach (self::BUILD_WIDTHS as $nominal) {
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
        if ($srcWidth > self::BODY_MAX_WIDTH) {
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
