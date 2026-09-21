<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Image\Delivery\Support\ThumbnailUrlRewriter;

/**
 * 목록 `thumbnail` 치환 규칙 테스트 (0.2.0).
 *
 * 지켜야 할 것은 "무엇을 바꾸는가" 보다 **"무엇을 안 바꾸는가"** 다 — 외부 주소·첨부
 * 미리보기·null·다른 필드를 건드리면 목록 화면이 조용히 깨진다.
 */
class ThumbnailUrlRewriterTest extends TestCase
{
    private const HASH = '53d814be8c1e';

    private const EDITOR_URL = '/api/plugins/sirsoft-ckeditor5/images/53d814be8c1e';

    private const VARIANT_URL = '/api/plugins/g7-image-delivery/variants/53d814be8c1e-240-abcd1234.webp';

    /** @return array<string, string> */
    private function urls(): array
    {
        return [self::HASH => self::VARIANT_URL];
    }

    // ── 대상 ────────────────────────────────────────────────────────────────

    public function test_에디터_해시_주소는_변환본_주소로_바뀐다(): void
    {
        $changed = false;
        $out = ThumbnailUrlRewriter::rewrite(
            ['data' => ['data' => [['id' => 1, 'thumbnail' => self::EDITOR_URL]]]],
            $this->urls(),
            $changed
        );

        $this->assertTrue($changed);
        $this->assertSame(self::VARIANT_URL, $out['data']['data'][0]['thumbnail']);
    }

    public function test_같은_해시가_여러_건_있어도_모두_바뀐다(): void
    {
        $changed = false;
        $out = ThumbnailUrlRewriter::rewrite(
            ['data' => [['thumbnail' => self::EDITOR_URL], ['thumbnail' => self::EDITOR_URL]]],
            $this->urls(),
            $changed
        );

        $this->assertSame(self::VARIANT_URL, $out['data'][0]['thumbnail']);
        $this->assertSame(self::VARIANT_URL, $out['data'][1]['thumbnail']);
    }

    public function test_대문자_해시도_같은_변환본을_찾는다(): void
    {
        $changed = false;
        $out = ThumbnailUrlRewriter::rewrite(
            ['thumbnail' => '/api/plugins/sirsoft-ckeditor5/images/53D814BE8C1E'],
            $this->urls(),
            $changed
        );

        $this->assertTrue($changed);
        $this->assertSame(self::VARIANT_URL, $out['thumbnail']);
    }

    // ── 비대상 ──────────────────────────────────────────────────────────────

    /**
     * @dataProvider 비대상값
     */
    public function test_비대상_값은_그대로_둔다(mixed $value): void
    {
        $changed = false;
        $out = ThumbnailUrlRewriter::rewrite(['thumbnail' => $value], $this->urls(), $changed);

        $this->assertFalse($changed);
        $this->assertSame($value, $out['thumbnail']);
    }

    /** @return array<string, array{mixed}> */
    public static function 비대상값(): array
    {
        return [
            'null'          => [null],
            '빈 문자열'      => [''],
            '외부 주소'      => ['https://example.com/photo.jpg'],
            '프로토콜 상대'  => ['//example.com/photo.jpg'],
            '첨부 미리보기'  => ['/api/modules/sirsoft-board/boards/free/attachment/abc123/preview'],
            '쿼리스트링 붙음' => ['/api/plugins/sirsoft-ckeditor5/images/53d814be8c1e?v=2'],
            '접두어만 같음'  => ['https://evil.test/api/plugins/sirsoft-ckeditor5/images/53d814be8c1e'],
            '해시 길이 다름' => ['/api/plugins/sirsoft-ckeditor5/images/53d814be8c'],
            '해시 아님'      => ['/api/plugins/sirsoft-ckeditor5/images/zzzzzzzzzzzz'],
        ];
    }

    public function test_변환본이_없는_해시는_원본_주소를_유지한다(): void
    {
        $changed = false;
        $out = ThumbnailUrlRewriter::rewrite(
            ['thumbnail' => '/api/plugins/sirsoft-ckeditor5/images/ffffffffffff'],
            $this->urls(),   // 이 해시는 맵에 없다
            $changed
        );

        $this->assertFalse($changed);
        $this->assertSame('/api/plugins/sirsoft-ckeditor5/images/ffffffffffff', $out['thumbnail']);
    }

    public function test_thumbnail_이_아닌_키는_같은_값이어도_건드리지_않는다(): void
    {
        $changed = false;
        $in = ['content' => self::EDITOR_URL, 'image' => self::EDITOR_URL, 'title' => '제목'];
        $out = ThumbnailUrlRewriter::rewrite($in, $this->urls(), $changed);

        $this->assertFalse($changed);
        $this->assertSame($in, $out);
    }

    public function test_다른_필드의_값과_순서가_보존된다(): void
    {
        $in = ['id' => 7, 'title' => 'T', 'thumbnail' => self::EDITOR_URL, 'view_count' => 3];
        $changed = false;
        $out = ThumbnailUrlRewriter::rewrite($in, $this->urls(), $changed);

        $this->assertSame(['id', 'title', 'thumbnail', 'view_count'], array_keys($out));
        $this->assertSame(7, $out['id']);
        $this->assertSame('T', $out['title']);
        $this->assertSame(3, $out['view_count']);
    }

    // ── 해시 수집 ───────────────────────────────────────────────────────────

    public function test_수집은_thumbnail_키에서만_중복_없이_모은다(): void
    {
        $hashes = [];
        ThumbnailUrlRewriter::collect([
            'data' => [
                ['thumbnail' => self::EDITOR_URL],
                ['thumbnail' => self::EDITOR_URL],                                  // 중복
                ['thumbnail' => '/api/plugins/sirsoft-ckeditor5/images/ffffffffffff'],
                ['thumbnail' => null],
                ['thumbnail' => 'https://example.com/a.png'],
                ['content' => self::EDITOR_URL],                                    // 다른 키
            ],
        ], $hashes);

        $this->assertSame([self::HASH, 'ffffffffffff'], $hashes);
    }

    public function test_hashOf_는_전체_일치일_때만_해시를_돌려준다(): void
    {
        $this->assertSame(self::HASH, ThumbnailUrlRewriter::hashOf(self::EDITOR_URL));
        $this->assertNull(ThumbnailUrlRewriter::hashOf(self::EDITOR_URL.'?v=2'));
        $this->assertNull(ThumbnailUrlRewriter::hashOf(123));
        $this->assertNull(ThumbnailUrlRewriter::hashOf(null));
    }
}
