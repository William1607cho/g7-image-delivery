<?php

namespace Plugins\G7\Image\Delivery\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Image\Delivery\Support\VariantPath;

class VariantPathTest extends TestCase
{
    public function test_공개_url_은_확장자로_끝난다(): void
    {
        // 확장자로 끝나야 Cloudflare 기본 캐시 판정에 걸린다.
        $url = VariantPath::publicUrl('53d814be8c1e', 960, 'abcd1234', 'webp');

        $this->assertSame(
            '/api/plugins/g7-image-delivery/variants/53d814be8c1e-960-abcd1234.webp',
            $url
        );
        $this->assertStringEndsWith('.webp', $url);
    }

    public function test_jpeg_변환본은_jpg_확장자다(): void
    {
        $this->assertStringEndsWith(
            '.jpg',
            VariantPath::publicUrl('53d814be8c1e', 960, 'abcd1234', 'jpg')
        );
    }

    public function test_저장_경로는_해시_앞_두_글자로_나뉜다(): void
    {
        $this->assertSame(
            '53/53d814be8c1e/1600.webp',
            VariantPath::relativePath('53d814be8c1e', 1600, 'webp')
        );
    }

    public function test_토큰은_8자_16진이다(): void
    {
        $token = VariantPath::token('53d814be8c1e', 960, 'webp', 1758153600, 12345);

        $this->assertSame(8, strlen($token));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{8}$/', $token);
    }

    public function test_재생성하면_토큰이_바뀐다(): void
    {
        $before = VariantPath::token('53d814be8c1e', 960, 'webp', 1758153600, 12345);
        $afterTime = VariantPath::token('53d814be8c1e', 960, 'webp', 1758153601, 12345);
        $afterSize = VariantPath::token('53d814be8c1e', 960, 'webp', 1758153600, 12346);

        $this->assertNotSame($before, $afterTime);
        $this->assertNotSame($before, $afterSize);
    }

    public function test_같은_입력이면_토큰이_같다(): void
    {
        $this->assertSame(
            VariantPath::token('53d814be8c1e', 960, 'webp', 1758153600, 12345),
            VariantPath::token('53d814be8c1e', 960, 'webp', 1758153600, 12345)
        );
    }

    public function test_content_type_매핑(): void
    {
        $this->assertSame('image/webp', VariantPath::contentTypeFor('webp'));
        $this->assertSame('image/jpeg', VariantPath::contentTypeFor('jpg'));
    }

    public function test_원본_url(): void
    {
        $this->assertSame(
            '/api/plugins/sirsoft-ckeditor5/images/53d814be8c1e',
            VariantPath::originalUrl('53d814be8c1e')
        );
    }
}
