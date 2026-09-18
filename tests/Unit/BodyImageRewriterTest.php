<?php

namespace Plugins\G7\Image\Delivery\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Image\Delivery\Support\BodyImageRewriter;

/**
 * 본문 가공기 — 가장 중요한 성질은 "대상 밖은 바이트가 그대로" 다.
 */
class BodyImageRewriterTest extends TestCase
{
    private const HASH = '53d814be8c1e';

    private function rewriter(bool $withVariant = true, bool $wrap = true): BodyImageRewriter
    {
        $variants = $withVariant ? [
            self::HASH => [
                'src' => '/api/plugins/g7-image-delivery/variants/'.self::HASH.'-960-abcd1234.webp',
                'srcset' => '/api/plugins/g7-image-delivery/variants/'.self::HASH.'-960-abcd1234.webp 960w',
                'width' => 960,
                'height' => 540,
            ],
        ] : [];

        return new BodyImageRewriter($variants, $wrap);
    }

    private function img(string $extra = ''): string
    {
        return '<img src="/api/plugins/sirsoft-ckeditor5/images/'.self::HASH.'"'.$extra.'>';
    }

    // ── 바이트 보존 ────────────────────────────────────────────────

    public function test_대상이_없으면_입력_그대로다(): void
    {
        $html = '<p>안녕하세요 &amp; 반갑습니다</p><figure class="image"><img src="/api/attachments/1/preview"></figure>';

        $this->assertSame($html, $this->rewriter()->rewrite($html));
    }

    public function test_img_가_아예_없으면_입력_그대로다(): void
    {
        $html = '<p>text only</p>';

        $this->assertSame($html, $this->rewriter()->rewrite($html));
    }

    public function test_대상_외_img_는_한_글자도_바뀌지_않는다(): void
    {
        $other = '<img src="https://example.com/a.png" alt=\'single quoted\' >';
        $html = '<div>'.$other.'</div>';

        $this->assertSame($html, $this->rewriter()->rewrite($html));
    }

    public function test_대상_태그_바깥_바이트는_보존된다(): void
    {
        $before = "<p>앞 &lt;문단&gt;</p>\n<figure class=\"image\">";
        $after = "</figure>\n<p>뒤   문단</p>";
        $out = $this->rewriter()->rewrite($before.$this->img().$after);

        $this->assertStringStartsWith($before, $out);
        $this->assertStringEndsWith($after, $out);
    }

    // ── 속성 ───────────────────────────────────────────────────────

    public function test_변환본이_있으면_srcset_과_치수를_넣는다(): void
    {
        $out = $this->rewriter()->rewrite($this->img());

        $this->assertStringContainsString('src="/api/plugins/g7-image-delivery/variants/', $out);
        $this->assertStringContainsString('srcset="', $out);
        $this->assertStringContainsString('sizes="(min-width: 960px) 848px, calc(100vw - 80px)"', $out);
        $this->assertStringContainsString('width="960"', $out);
        $this->assertStringContainsString('height="540"', $out);
    }

    public function test_첫_이미지는_fetchpriority_고_lazy_가_아니다(): void
    {
        $out = $this->rewriter()->rewrite($this->img(), true);

        $this->assertStringContainsString('fetchpriority="high"', $out);
        $this->assertStringNotContainsString('loading=', $out);
        $this->assertStringContainsString('decoding="async"', $out);
    }

    public function test_둘째부터는_lazy_다(): void
    {
        $out = $this->rewriter()->rewrite($this->img().$this->img(), true);

        $this->assertSame(1, substr_count($out, 'fetchpriority="high"'));
        $this->assertSame(1, substr_count($out, 'loading="lazy"'));
        $this->assertSame(2, substr_count($out, 'decoding="async"'));
    }

    public function test_markFirst_가_거짓이면_전부_lazy_다(): void
    {
        $out = $this->rewriter()->rewrite($this->img().$this->img(), false);

        $this->assertStringNotContainsString('fetchpriority', $out);
        $this->assertSame(2, substr_count($out, 'loading="lazy"'));
    }

    public function test_변환본이_없어도_lazy_decoding_링크는_붙는다(): void
    {
        $out = $this->rewriter(withVariant: false)->rewrite($this->img());

        $this->assertStringContainsString('src="/api/plugins/sirsoft-ckeditor5/images/'.self::HASH.'"', $out);
        $this->assertStringNotContainsString('srcset', $out);
        $this->assertStringContainsString('loading="lazy"', $out);
        $this->assertStringContainsString('decoding="async"', $out);
        $this->assertStringContainsString('<a href="/api/plugins/sirsoft-ckeditor5/images/'.self::HASH.'"', $out);
    }

    public function test_원래_속성은_보존된다(): void
    {
        $out = $this->rewriter()->rewrite($this->img(' alt="설명" class="x" data-foo="1"'));

        $this->assertStringContainsString('alt="설명"', $out);
        $this->assertStringContainsString('class="x"', $out);
        $this->assertStringContainsString('data-foo="1"', $out);
    }

    public function test_변환본이_있으면_원래_치수_속성을_우리_값으로_대체한다(): void
    {
        $out = $this->rewriter()->rewrite($this->img(' width="4000" height="2250"'));

        $this->assertStringNotContainsString('width="4000"', $out);
        $this->assertStringContainsString('width="960"', $out);
    }

    public function test_변환본이_없으면_원래_치수_속성을_살린다(): void
    {
        $out = $this->rewriter(withVariant: false)->rewrite($this->img(' width="600" height="400"'));

        $this->assertStringContainsString('width="600"', $out);
        $this->assertStringContainsString('height="400"', $out);
    }

    public function test_작은따옴표_값에_든_큰따옴표는_이스케이프된다(): void
    {
        $out = $this->rewriter()->rewrite($this->img(' alt=\'그는 "안녕" 이라 했다\''));

        $this->assertStringContainsString('&quot;', $out);
        $this->assertStringNotContainsString('alt="그는 "안녕"', $out);
    }

    // ── 링크 감싸기 ────────────────────────────────────────────────

    public function test_링크로_감싼다(): void
    {
        $out = $this->rewriter()->rewrite($this->img());

        $this->assertStringContainsString(
            '<a href="/api/plugins/sirsoft-ckeditor5/images/'.self::HASH.'" target="_blank" rel="noopener">',
            $out
        );
        $this->assertStringEndsWith('</a>', $out);
    }

    public function test_이미_링크_안이면_이중으로_감싸지_않는다(): void
    {
        $html = '<a href="https://example.com">'.$this->img().'</a>';
        $out = $this->rewriter()->rewrite($html);

        $this->assertSame(1, substr_count($out, '<a '));
        $this->assertStringContainsString('<a href="https://example.com">', $out);
    }

    public function test_링크가_닫힌_뒤의_이미지는_다시_감싼다(): void
    {
        $html = '<a href="https://example.com">글</a>'.$this->img();
        $out = $this->rewriter()->rewrite($html);

        $this->assertSame(2, substr_count($out, '<a '));
    }

    public function test_감싸기를_끄면_링크를_만들지_않는다(): void
    {
        $out = $this->rewriter(wrap: false)->rewrite($this->img());

        $this->assertStringNotContainsString('<a ', $out);
        $this->assertStringContainsString('loading="lazy"', $out);
    }

    // ── 까다로운 입력 ──────────────────────────────────────────────

    public function test_속성값에_든_꺾쇠는_태그_끝으로_보지_않는다(): void
    {
        $out = $this->rewriter()->rewrite($this->img(' alt="a > b"'));

        $this->assertStringContainsString('alt="a > b"', $out);
        $this->assertStringContainsString('srcset=', $out);
    }

    public function test_자기닫힘_태그는_그_형태를_유지한다(): void
    {
        $html = '<img src="/api/plugins/sirsoft-ckeditor5/images/'.self::HASH.'" />';
        $out = $this->rewriter()->rewrite($html);

        $this->assertStringContainsString('/>', $out);
    }

    public function test_절대_url_형태의_src_도_대상이다(): void
    {
        $html = '<img src="https://blog.example.com/api/plugins/sirsoft-ckeditor5/images/'.self::HASH.'">';
        $out = $this->rewriter()->rewrite($html);

        $this->assertStringContainsString('srcset=', $out);
    }

    public function test_해시가_12자_16진이_아니면_대상이_아니다(): void
    {
        $html = '<img src="/api/plugins/sirsoft-ckeditor5/images/ZZZZZZZZZZZZ">';

        $this->assertSame($html, $this->rewriter()->rewrite($html));
    }

    public function test_닫히지_않은_꺾쇠는_본문으로_흘린다(): void
    {
        $html = '3 < 5 그리고 '.$this->img();
        $out = $this->rewriter()->rewrite($html);

        $this->assertStringStartsWith('3 < 5 그리고 ', $out);
        $this->assertStringContainsString('srcset=', $out);
    }

    public function test_변환본_맵에_없는_해시는_변환본_없음으로_다룬다(): void
    {
        $other = '<img src="/api/plugins/sirsoft-ckeditor5/images/aaaaaaaaaaaa">';
        $out = $this->rewriter()->rewrite($other);

        $this->assertStringNotContainsString('srcset', $out);
        $this->assertStringContainsString('loading="lazy"', $out);
    }
}
