<?php

namespace Plugins\G7\Image\Delivery\Support;

/**
 * 목록 응답의 `thumbnail` 값을 변환본 주소로 갈아끼우는 순수 클래스 (0.2.0).
 *
 * 파일도 DB 도 건드리지 않는다 — 입력은 응답 배열과 "해시 → 변환본 URL" 맵뿐이다.
 * {@see BodyImageRewriter} 와 같은 이유로 따로 떼어냈다: 규칙이 자잘하고
 * (전체 일치만, 외부 주소 제외, 변환본 없으면 폴백) 틀리면 조용히 잘못된 주소가 나간다.
 *
 * ## 코어의 판정을 다시 하지 않는다
 *
 * "값이 에디터 해시 주소일 때만" 이 유일한 조건이다. 코어가 비밀글·권한 판정으로 `null`
 * 을 내보낸 자리는 애초에 대상이 아니고, 코어가 값을 내보낸 자리만 바뀐다. 확장이 권한
 * 게이트를 복제할 일이 없다.
 */
final class ThumbnailUrlRewriter
{
    /**
     * 다시 쓸 대상이 되는 JSON 키.
     */
    public const FIELD = 'thumbnail';

    /**
     * 에디터 이미지 해시 주소 — **전체 일치**만 대상으로 삼는다.
     *
     * 부분 일치로 느슨하게 잡을 이유가 없다: 목록 응답에는 본문 HTML 이 없고, 이 필드에는
     * URL 하나만 들어온다. 전체 일치로 두면 첨부 미리보기 주소·외부 주소·쿼리스트링이
     * 붙은 변형이 전부 자동으로 비대상이 된다.
     */
    public const PATTERN = '#^/api/plugins/sirsoft-ckeditor5/images/([a-f0-9]{12})$#';

    /**
     * 값에서 에디터 이미지 해시를 뽑습니다. 대상이 아니면 null.
     */
    public static function hashOf(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return preg_match(self::PATTERN, $value, $m) === 1 ? strtolower($m[1]) : null;
    }

    /**
     * 응답 배열을 훑어 대상 해시를 모읍니다 (DB 조회를 한 번에 하기 위해).
     *
     * @param  list<string>  $hashes  (참조)
     */
    public static function collect(mixed $node, array &$hashes): void
    {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $child) {
            if ($key === self::FIELD) {
                $hash = self::hashOf($child);

                if ($hash !== null && ! in_array($hash, $hashes, true)) {
                    $hashes[] = $hash;
                }

                continue;
            }

            self::collect($child, $hashes);
        }
    }

    /**
     * `thumbnail` 값만 갈아끼웁니다. 다른 키는 값도 순서도 그대로 둡니다.
     *
     * 맵에 없는 해시는 **바꾸지 않는다** — 변환본이 없으면 원본 주소가 그대로 남는 것이
     * 기본 동작이고, 예외 처리가 아니다.
     *
     * @param  array<string, string>  $urls  해시 → 변환본 URL
     * @param  bool  $changed  (참조) 한 곳이라도 바뀌었는지
     */
    public static function rewrite(mixed $node, array $urls, bool &$changed): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        foreach ($node as $key => $child) {
            if ($key === self::FIELD) {
                $hash = self::hashOf($child);
                $replacement = $hash !== null ? ($urls[$hash] ?? null) : null;

                if ($replacement !== null) {
                    $node[$key] = $replacement;
                    $changed = true;
                }

                continue;
            }

            $node[$key] = self::rewrite($child, $urls, $changed);
        }

        return $node;
    }
}
