<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Image\Delivery\Support\VariantLookupResult;

/**
 * 공개 계약 `ThumbnailVariants::lookup()` 결과 조립 규칙 테스트 (0.3.0).
 *
 * 지켜야 할 것: 대상 판정이 목록 썸네일 다시쓰기와 같을 것, 변환본이 없으면 원본 주소를 대신 넣지 않고
 * "없음" 으로 둘 것, 설정을 끄면 전부 없음일 것.
 */
class VariantLookupResultTest extends TestCase
{
    private const HASH = '53d814be8c1e';

    private const EDITOR_URL = '/api/plugins/sirsoft-ckeditor5/images/53d814be8c1e';

    private const OTHER_URL = '/api/plugins/sirsoft-ckeditor5/images/0000000000aa';

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        $base = ['upload_hash' => self::HASH, 'src_width' => 2000, 'src_height' => 1125, 'src_mime' => 'image/jpeg'];

        return [
            $base + ['width' => 960, 'url' => '/v/53d814be8c1e-960.webp', 'out_width' => 960, 'out_height' => 540],
            $base + ['width' => 240, 'url' => '/v/53d814be8c1e-240.webp', 'out_width' => 240, 'out_height' => 135],
            $base + ['width' => 1600, 'url' => '/v/53d814be8c1e-1600.webp', 'out_width' => 1600, 'out_height' => 900],
        ];
    }

    public function test_변환본이_있으면_폭별로_오름차순_묶는다(): void
    {
        $out = VariantLookupResult::build([self::EDITOR_URL], $this->rows(), true);

        $this->assertTrue($out[self::EDITOR_URL]['available']);
        $this->assertSame([240, 960, 1600], array_keys($out[self::EDITOR_URL]['variants']));
        $this->assertSame(['url' => '/v/53d814be8c1e-240.webp', 'width' => 240, 'height' => 135], $out[self::EDITOR_URL]['variants'][240]);
        $this->assertSame(['width' => 2000, 'height' => 1125, 'mime' => 'image/jpeg'], $out[self::EDITOR_URL]['source']);
    }

    public function test_변환본이_없는_에디터_주소는_없음이고_원본을_대신_넣지_않는다(): void
    {
        $out = VariantLookupResult::build([self::OTHER_URL], $this->rows(), true);

        $this->assertSame(['available' => false, 'variants' => [], 'source' => null], $out[self::OTHER_URL]);
    }

    public function test_대상이_아닌_주소는_없음이다(): void
    {
        $urls = [
            'https://example.com/a.jpg',
            '/api/modules/sirsoft-board/boards/x/attachments/abc/preview',
            self::EDITOR_URL.'?v=1',
            'https://blog.example/api/plugins/sirsoft-ckeditor5/images/53d814be8c1e',
        ];
        $out = VariantLookupResult::build($urls, $this->rows(), true);

        foreach ($urls as $url) {
            $this->assertFalse($out[$url]['available'], $url);
            $this->assertSame([], $out[$url]['variants'], $url);
        }
    }

    public function test_문자열이_아니거나_빈_값은_결과에_없고_중복은_한_번만(): void
    {
        $out = VariantLookupResult::build([null, '', 12, self::EDITOR_URL, self::EDITOR_URL], $this->rows(), true);

        $this->assertSame([self::EDITOR_URL], array_keys($out));
    }

    public function test_설정을_끄면_전부_없음이다(): void
    {
        $out = VariantLookupResult::build([self::EDITOR_URL], $this->rows(), false);

        $this->assertFalse($out[self::EDITOR_URL]['available']);
        $this->assertNull($out[self::EDITOR_URL]['source']);
    }

    public function test_표식_행_폭0과_계획_밖_폭은_무시한다(): void
    {
        $rows = [
            ['upload_hash' => self::HASH, 'width' => 0, 'url' => '/v/x-0-.webp', 'out_width' => 0, 'out_height' => 0],
            ['upload_hash' => self::HASH, 'width' => 480, 'url' => '/v/x-480.webp', 'out_width' => 480, 'out_height' => 270],
        ];
        $out = VariantLookupResult::build([self::EDITOR_URL], $rows, true);

        $this->assertFalse($out[self::EDITOR_URL]['available']);
    }

    public function test_해시_대소문자는_소문자로_맞춘다(): void
    {
        $upper = '/api/plugins/sirsoft-ckeditor5/images/53D814BE8C1E';
        $this->assertSame([], VariantLookupResult::hashesByUrl([$upper]), '전체 일치 패턴은 소문자 16진수만 받는다(목록 다시쓰기와 같다)');
        $this->assertSame([self::EDITOR_URL => self::HASH], VariantLookupResult::hashesByUrl([self::EDITOR_URL]));
    }
}
