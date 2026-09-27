<?php

namespace Plugins\G7\Image\Delivery\Support;

/**
 * 공개 계약 {@see \Plugins\G7\Image\Delivery\PublicApi\ThumbnailVariants} 의 결과 조립 (0.3.0). 순수 클래스.
 *
 * 파일도 DB 도 건드리지 않는다 — 입력은 호출자가 넘긴 주소 목록과, 계약이 한 번에 읽어 온 변환본 행뿐이다.
 * 계약 쪽 I/O(조회 1회)와 떼어 둔 이유는 규칙(대상 판정·폭별 묶기·없음 표시)을 DB 없이 시험하기 위해서다.
 *
 * ## 대상 판정은 목록 썸네일과 같다
 *
 * 에디터 이미지 해시 주소의 **전체 일치**만 대상이다({@see ThumbnailUrlRewriter::hashOf()}). 게시판 목록
 * `thumbnail` 다시쓰기와 같은 함수를 쓰므로, 목록에서 바뀌는 주소와 계약이 변환본을 돌려주는 주소가 같다.
 *
 * ## 없으면 "없음"
 *
 * 변환본이 하나도 없거나 대상이 아닌 주소는 `available = false`, `variants = []` 다. 원본 주소를 대신 넣지
 * 않는다 — 원본을 쓸지, 대체 틀을 그릴지는 호출자가 정한다.
 */
final class VariantLookupResult
{
    /**
     * 주소 목록 → 대상 해시 (조회 전 단계).
     *
     * @param  array<int, mixed>  $urls
     * @return array<string, string> 주소 => 해시 (대상인 주소만)
     */
    public static function hashesByUrl(array $urls): array
    {
        $out = [];

        foreach ($urls as $url) {
            $hash = ThumbnailUrlRewriter::hashOf($url);

            if ($hash !== null) {
                $out[$url] = $hash;
            }
        }

        return $out;
    }

    /**
     * 주소별 결과를 조립합니다.
     *
     * 행 모양: `upload_hash`, `width`(공칭 폭), `url`, `out_width`, `out_height`, `src_width`, `src_height`, `src_mime`.
     * 준비된(ready) 행만 넘겨야 한다 — 표식 행(width 0)은 폭 목록에 없어 여기서도 걸러진다.
     *
     * @param  array<int, mixed>  $urls  호출자가 넘긴 주소(순서·중복 무관)
     * @param  iterable<array<string, mixed>>  $rows
     * @param  bool  $enabled  플러그인 "본문 이미지 가공 사용" 설정. 끄면 전부 없음(목록 다시쓰기와 같다)
     * @return array<string, array{available: bool, variants: array<int, array{url: string, width: int, height: int}>, source: ?array{width: int, height: int, mime: string}}>
     */
    public static function build(array $urls, iterable $rows, bool $enabled): array
    {
        $byHash = [];

        foreach ($rows as $row) {
            $nominal = (int) ($row['width'] ?? 0);

            if (! in_array($nominal, VariantPlan::BUILD_WIDTHS, true)) {
                continue;
            }

            $hash = strtolower((string) ($row['upload_hash'] ?? ''));
            $byHash[$hash]['variants'][$nominal] = [
                'url' => (string) $row['url'],
                'width' => (int) ($row['out_width'] ?? 0),
                'height' => (int) ($row['out_height'] ?? 0),
            ];
            $byHash[$hash]['source'] ??= [
                'width' => (int) ($row['src_width'] ?? 0),
                'height' => (int) ($row['src_height'] ?? 0),
                'mime' => (string) ($row['src_mime'] ?? ''),
            ];
        }

        $hashes = self::hashesByUrl($urls);
        $out = [];

        foreach ($urls as $url) {
            if (! is_string($url) || $url === '' || isset($out[$url])) {
                continue;
            }

            $found = $enabled && isset($hashes[$url]) ? ($byHash[$hashes[$url]] ?? null) : null;
            $variants = $found['variants'] ?? [];
            ksort($variants);

            $out[$url] = [
                'available' => $variants !== [],
                'variants' => $variants,
                'source' => $variants !== [] ? $found['source'] : null,
            ];
        }

        return $out;
    }
}
