<?php

namespace Plugins\G7\Image\Delivery\PublicApi;

use Plugins\G7\Image\Delivery\Models\ImageVariant;
use Plugins\G7\Image\Delivery\Support\VariantLookupResult;
use Plugins\G7\Image\Delivery\Support\VariantPath;
use Plugins\G7\Image\Delivery\Support\VariantPlan;

/**
 * 다른 확장이 쓰는 **공개 계약** — 이미지 주소 → 변환본 조회 (0.3.0 신설).
 *
 * 이 클래스의 이름·상수 `VERSION`·메서드 `lookup()` 의 입력과 출력은 약속이다. 변환본 저장 방식
 * (표·경로·토큰)이 바뀌어도 이 입출력은 유지한다. 바꿔야 하면 `VERSION` 을 올리고 CHANGELOG 에 적는다.
 * 다른 확장은 이 플러그인의 내부 클래스(`Models\*`, `Support\*` 등)나 표를 직접 읽지 말고 이것만 쓴다.
 *
 * 쓰는 쪽(예: g7-home-widgets 갤러리·웹진 위젯)의 판정: 코어 플러그인 활성 여부
 * (`PluginRepositoryInterface::findActiveByIdentifier('g7-image-delivery')`) **그리고**
 * `class_exists(ThumbnailVariants::class) && ThumbnailVariants::VERSION >= 1`.
 *
 * **권한 판정을 하지 않는다.** 주소만 받는다. 비밀글처럼 보여서는 안 되는 이미지의 주소는 호출자가
 * 애초에 넘기지 않는다(코어 `PostResource` 가 비밀글 `thumbnail` 을 null 로 내는 것과 같은 자리).
 */
final class ThumbnailVariants
{
    /** 계약 판본. 호출 측은 `>= 1` 을 확인한다. */
    public const VERSION = 1;

    /** 한 번에 받는 주소 수 상한(이보다 많으면 앞에서부터 이만큼만 처리) */
    public const MAX_URLS = 200;

    /**
     * 주소마다 준비된 변환본을 돌려준다. 조회는 항목 수와 무관하게 **1회**(대상 주소가 없으면 0회).
     *
     * 입력: 이미지 주소 문자열 목록. 대상은 에디터 업로드 이미지 주소
     * (`/api/plugins/sirsoft-ckeditor5/images/<12자리 16진수>`, 전체 일치 — 게시판 목록 썸네일 다시쓰기와 같은 규칙)다.
     * 문자열이 아니거나 빈 값은 결과에 없다.
     *
     * 출력: 주소 => 다음 값.
     * - `available`(bool): 준비된 변환본이 하나라도 있으면 true.
     * - `variants`: 공칭 폭(240·960·1600 중 있는 것, 오름차순) => `url`(공개 주소)·`width`·`height`(실제 픽셀).
     *   원본보다 넓은 폭은 만들지 않으므로 작은 원본은 240 만 있거나 아예 없을 수 있다.
     * - `source`: 변환본이 있을 때 원본의 `width`·`height`·`mime`. 없으면 null.
     *
     * 변환본이 없거나(아직 생성 전 — 스케줄러가 10분마다 만든다), 대상이 아닌 주소거나, 이 플러그인의
     * "본문 이미지 가공 사용" 설정이 꺼져 있으면 `available = false`, `variants = []`, `source = null` 이다.
     * **원본 주소를 대신 돌려주지 않는다** — 원본을 쓸지는 호출자가 정한다.
     *
     * @param  array<int, mixed>  $urls
     * @return array<string, array{available: bool, variants: array<int, array{url: string, width: int, height: int}>, source: ?array{width: int, height: int, mime: string}}>
     */
    public static function lookup(array $urls): array
    {
        $urls = array_slice(array_values($urls), 0, self::MAX_URLS);
        $enabled = (bool) plugin_setting(VariantPath::IDENTIFIER, 'enabled', true);
        $hashes = $enabled ? array_values(array_unique(VariantLookupResult::hashesByUrl($urls))) : [];

        return VariantLookupResult::build($urls, $hashes === [] ? [] : self::rowsFor($hashes), $enabled);
    }

    /**
     * 해시 집합의 준비된 변환본 행 (조회 1회). `(upload_hash, status)` 인덱스를 쓴다.
     *
     * @param  list<string>  $hashes
     * @return list<array<string, mixed>>
     */
    private static function rowsFor(array $hashes): array
    {
        return ImageVariant::query()
            ->whereIn('upload_hash', $hashes)
            ->where('status', ImageVariant::STATUS_READY)
            ->whereIn('width', VariantPlan::BUILD_WIDTHS)
            ->orderBy('width')
            ->get()
            ->map(fn (ImageVariant $v) => [
                'upload_hash' => (string) $v->upload_hash,
                'width' => (int) $v->width,
                'url' => $v->publicUrl(),
                'out_width' => (int) $v->out_width,
                'out_height' => (int) $v->out_height,
                'src_width' => (int) $v->src_width,
                'src_height' => (int) $v->src_height,
                'src_mime' => (string) ($v->src_mime ?? ''),
            ])
            ->all();
    }
}
