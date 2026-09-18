<?php

namespace Plugins\G7\Image\Delivery\Support;

/**
 * 글·댓글 본문 HTML 에서 **에디터 업로드 이미지 img 태그만** 다시 쓰는 순수 클래스.
 *
 * ## 왜 DOM 파서를 쓰지 않는가
 *
 * `DOMDocument` 로 읽어 `saveHTML()` 하면 **문서 전체가 재직렬화**된다 — 엔티티 표기,
 * 빈 요소를 닫는 방식, 속성 따옴표, 공백이 전부 파서 취향대로 바뀐다. 그러면 우리가
 * 건드리지 않기로 한 부분(다른 img·나머지 HTML)까지 바이트가 달라진다.
 *
 * 그래서 입력 문자열을 왼쪽에서 오른쪽으로 **한 번 훑으면서**
 *
 *  - `<a>` 열림·닫힘만 세어 "지금 링크 안인지" 를 추적하고,
 *  - 대상 `<img …>` 토큰을 만나면 **그 태그 문자열만** 새로 조립해 갈아끼우고,
 *  - 그 밖의 모든 바이트는 **한 글자도 손대지 않고 그대로 복사**한다.
 *
 * 결과적으로 "대상 img 태그 바깥은 입력 바이트열 그대로" 가 구현으로 보장된다.
 *
 * ## 대상 판정
 *
 * `src` 가 `/api/plugins/sirsoft-ckeditor5/images/{12자 16진}` 인 img 만 대상이다.
 * 첨부 이미지·외부 URL·data: URI 는 대상이 아니며 **속성 하나도 더하지 않는다.**
 */
final class BodyImageRewriter
{
    /**
     * 에디터 업로드 이미지 src 판정. 절대 URL 형태(과거 본문)도 함께 받는다.
     */
    private const SRC_PATTERN =
        '#^(?:https?://[^/]+)?/api/plugins/sirsoft-ckeditor5/images/([a-f0-9]{12})(?:[?\#].*)?$#i';

    /**
     * 이 클래스가 소유하는 속성 — 원본 태그에 있어도 버리고 새로 쓴다.
     */
    private const OWNED_ATTRIBUTES = [
        'src', 'srcset', 'sizes', 'loading', 'decoding', 'fetchpriority',
    ];

    /**
     * @param  array<string, array{src: string, srcset: string, width: int, height: int}>  $variants
     *                                       해시 => 변환본 정보. 없는 해시는 "변환본 없음" 으로 다룬다.
     * @param  bool  $wrapInLink  링크로 감쌀지 여부
     * @param  string  $sizes  `sizes` 속성 값
     */
    public function __construct(
        private readonly array $variants = [],
        private readonly bool $wrapInLink = true,
        private readonly string $sizes = VariantPlan::SIZES,
    ) {}

    /**
     * 본문 HTML 을 가공해 반환합니다.
     *
     * @param  string  $html  원본 HTML
     * @param  bool  $markFirst  첫 대상 이미지에 `fetchpriority="high"` 를 줄지 여부.
     *                           글 본문에서만 true 로 준다 — 한 화면에 high 가 여럿이면 의미가 없다.
     * @return string 가공된 HTML (대상이 없으면 입력과 바이트가 같다)
     */
    public function rewrite(string $html, bool $markFirst = false): string
    {
        if ($html === '' || stripos($html, '<img') === false) {
            return $html;
        }

        $length = strlen($html);
        $out = '';
        $cursor = 0;
        $anchorDepth = 0;
        $firstPending = $markFirst;

        while ($cursor < $length) {
            $lt = strpos($html, '<', $cursor);

            if ($lt === false) {
                $out .= substr($html, $cursor);
                break;
            }

            $out .= substr($html, $cursor, $lt - $cursor);

            $tagEnd = $this->findTagEnd($html, $lt);

            if ($tagEnd === null) {
                // 닫히지 않은 `<` — 태그가 아니라 본문 텍스트로 보고 그대로 흘린다.
                $out .= '<';
                $cursor = $lt + 1;

                continue;
            }

            $tag = substr($html, $lt, $tagEnd - $lt + 1);

            if ($this->isTag($tag, 'img')) {
                $out .= $this->rewriteImgTag($tag, $anchorDepth > 0, $firstPending, $rewritten);
                if ($rewritten) {
                    $firstPending = false;
                }
            } else {
                if ($this->isTag($tag, 'a')) {
                    // 자기 닫힘 `<a/>` 는 실무에서 나오지 않지만 세지 않는 편이 안전하다.
                    if (! str_ends_with(rtrim(substr($tag, 0, -1)), '/')) {
                        $anchorDepth++;
                    }
                } elseif ($this->isClosingTag($tag, 'a')) {
                    $anchorDepth = max(0, $anchorDepth - 1);
                }

                $out .= $tag;
            }

            $cursor = $tagEnd + 1;
        }

        return $out;
    }

    /**
     * `$from` 위치에서 시작하는 태그의 닫는 `>` 위치를 찾습니다 (따옴표 안의 `>` 는 무시).
     *
     * @return int|null `>` 의 인덱스, 끝까지 닫히지 않으면 null
     */
    private function findTagEnd(string $html, int $from): ?int
    {
        $length = strlen($html);
        $quote = null;

        for ($i = $from + 1; $i < $length; $i++) {
            $char = $html[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '>') {
                return $i;
            }
        }

        return null;
    }

    /**
     * 태그 문자열이 `<name …>` 형태인지 판정합니다.
     */
    private function isTag(string $tag, string $name): bool
    {
        return (bool) preg_match('/^<'.preg_quote($name, '/').'(?=[\s\/>])/i', $tag);
    }

    /**
     * 태그 문자열이 `</name>` 형태인지 판정합니다.
     */
    private function isClosingTag(string $tag, string $name): bool
    {
        return (bool) preg_match('/^<\/\s*'.preg_quote($name, '/').'\s*>$/i', $tag);
    }

    /**
     * img 태그 하나를 가공합니다.
     *
     * @param  string  $tag  `<img …>` 원문
     * @param  bool  $insideAnchor  이미 링크 안인지
     * @param  bool  $wantFirst  이 이미지가 "첫 이미지" 대우를 받을 후보인지
     * @param  bool|null  $rewritten  (출력) 실제로 가공했는지 — 대상이 아니면 false
     * @return string 가공 결과 (대상이 아니면 입력 그대로)
     */
    private function rewriteImgTag(string $tag, bool $insideAnchor, bool $wantFirst, ?bool &$rewritten): string
    {
        $rewritten = false;

        $attributes = $this->parseAttributes($tag);
        $src = $this->attributeValue($attributes, 'src');

        if ($src === null || ! preg_match(self::SRC_PATTERN, trim($src), $matches)) {
            // 대상 외 — 한 글자도 건드리지 않는다.
            return $tag;
        }

        $hash = strtolower($matches[1]);
        $variant = $this->variants[$hash] ?? null;
        $selfClosing = (bool) preg_match('/\/\s*>$/', $tag);

        $kept = [];
        foreach ($attributes as [$name, $value]) {
            $lower = strtolower($name);

            if (in_array($lower, self::OWNED_ATTRIBUTES, true)) {
                continue;
            }

            // 변환본이 있을 때만 치수를 우리가 정한다 — 없으면 원래 값을 살린다.
            if ($variant !== null && ($lower === 'width' || $lower === 'height')) {
                continue;
            }

            $kept[] = [$name, $value];
        }

        $own = [];

        if ($variant !== null) {
            $own[] = ['src', $variant['src']];
            $own[] = ['srcset', $variant['srcset']];
            $own[] = ['sizes', $this->sizes];
            $own[] = ['width', (string) $variant['width']];
            $own[] = ['height', (string) $variant['height']];
        } else {
            // 변환본이 없어도 src 는 원본 그대로 두고 나머지 속성만 붙인다.
            $own[] = ['src', $src];
        }

        $own[] = ['decoding', 'async'];

        if ($wantFirst) {
            // 첫 이미지는 lazy 를 붙이지 않는다 (LCP 지연).
            $own[] = ['fetchpriority', 'high'];
        } else {
            $own[] = ['loading', 'lazy'];
        }

        $rebuilt = '<img';
        foreach (array_merge($kept, $own) as [$name, $value]) {
            $rebuilt .= $value === null
                ? ' '.$name
                : ' '.$name.'="'.$this->escapeAttribute($value).'"';
        }
        $rebuilt .= $selfClosing ? ' />' : '>';

        $rewritten = true;

        if (! $this->wrapInLink || $insideAnchor) {
            return $rebuilt;
        }

        return '<a href="'.$this->escapeAttribute(VariantPath::originalUrl($hash))
            .'" target="_blank" rel="noopener">'.$rebuilt.'</a>';
    }

    /**
     * img 태그의 속성을 **원래 순서대로** 뽑습니다.
     *
     * @return list<array{0: string, 1: string|null}> [이름, 값] — 값 없는 속성은 null
     */
    private function parseAttributes(string $tag): array
    {
        // `<img` 와 닫는 `>`(및 자기닫힘 `/`) 사이만 본다.
        $inner = preg_replace('/^<img/i', '', substr($tag, 0, -1));
        $inner = (string) preg_replace('/\/\s*$/', '', (string) $inner);

        $pattern = '/([^\s=\/>"\'][^\s=\/>]*)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?/';

        if (! preg_match_all($pattern, $inner, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $attributes = [];

        foreach ($matches as $match) {
            $name = $match[1];

            if (! isset($match[2]) || $match[2] === '') {
                $attributes[] = [$name, null];

                continue;
            }

            $raw = $match[2];
            $first = $raw[0];
            $value = ($first === '"' || $first === "'") ? substr($raw, 1, -1) : $raw;

            $attributes[] = [$name, $value];
        }

        return $attributes;
    }

    /**
     * 속성 목록에서 이름으로 값을 찾습니다 (대소문자 무시).
     *
     * @param  list<array{0: string, 1: string|null}>  $attributes
     */
    private function attributeValue(array $attributes, string $name): ?string
    {
        foreach ($attributes as [$attributeName, $value]) {
            if (strcasecmp($attributeName, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * 속성 값을 큰따옴표 안에 넣을 수 있게 만듭니다.
     *
     * 원래 작은따옴표로 감싸여 있던 값에 `"` 가 들어 있을 수 있으므로 그것만 바꾼다.
     * 나머지는 원문 그대로 둔다 — 이미 엔티티로 적혀 있는 값을 다시 이스케이프하면
     * `&amp;amp;` 처럼 두 번 먹는다.
     */
    private function escapeAttribute(string $value): string
    {
        return str_replace('"', '&quot;', $value);
    }
}
