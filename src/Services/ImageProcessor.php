<?php

namespace Plugins\G7\Image\Delivery\Services;

use Illuminate\Support\Facades\Log;
use Plugins\G7\Image\Delivery\Support\VariantPlan;

/**
 * imagick 얇은 감싸개 — 치수 읽기와 인코딩만 한다.
 *
 * GD 를 쓰지 않는 이유: 이 컨테이너의 GD 는 번들 빌드라 **WebP 인코더가 없다**
 * (`php -i` 의 GD 절에 `WebP Support` 줄 자체가 없다). 코어 `App\Support\ImageResizer`
 * 가 GD 하드코딩이라 재사용할 수 없는 것도 같은 이유다.
 *
 * 모든 작업 전에 자원 한도를 건다 — `max_execution_time` 이 0(무제한)이라 시간 상한도
 * 코드가 직접 책임져야 한다.
 */
class ImageProcessor
{
    /** imagick 메모리 한도 (바이트). PHP memory_limit 256M 안에서 여유를 남긴다. */
    private const LIMIT_MEMORY = 128 * 1024 * 1024;

    /** imagick 메모리맵 한도 (바이트). */
    private const LIMIT_MAP = 256 * 1024 * 1024;

    /** imagick 디스크 한도 (바이트). */
    private const LIMIT_DISK = 1024 * 1024 * 1024;

    /** imagick 면적 한도 (픽셀). */
    private const LIMIT_AREA = 96_000_000;

    /** imagick 가로·세로 한도 (픽셀). */
    private const LIMIT_DIMENSION = 20000;

    /** imagick 시간 한도 (초). */
    private const LIMIT_TIME = 20;

    /** 원본 픽셀 수 상한 — 디코드 전에 ping 으로 걸러 압축 폭탄을 막는다. */
    public const MAX_SOURCE_PIXELS = 80_000_000;

    /** 1건당 벽시계 상한 (초). */
    public const MAX_WALL_SECONDS = 20;

    /**
     * imagick 을 쓸 수 있는지 (확장 적재 + WebP 인코딩 지원).
     */
    public function isUsable(): bool
    {
        if (! extension_loaded('imagick') || ! class_exists(\Imagick::class)) {
            return false;
        }

        try {
            return in_array('WEBP', \Imagick::queryFormats('WEBP'), true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 파일을 디코드하지 않고 치수만 읽습니다.
     *
     * @return array{width: int, height: int}|null 읽지 못하면 null
     */
    public function ping(string $absolutePath): ?array
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        $imagick = null;

        try {
            $imagick = new \Imagick;
            $this->applyLimits($imagick);
            $imagick->pingImage($absolutePath);

            $width = (int) $imagick->getImageWidth();
            $height = (int) $imagick->getImageHeight();

            return $width > 0 && $height > 0 ? ['width' => $width, 'height' => $height] : null;
        } catch (\Throwable $e) {
            Log::warning('[g7-image-delivery] 이미지 치수 읽기 실패', ['error' => $e->getMessage()]);

            return null;
        } finally {
            $imagick?->clear();
        }
    }

    /**
     * 원본을 지정 가로로 축소해 인코딩한 바이트열을 반환합니다 (파일에 쓰지 않음).
     *
     * 세로는 가로 비율대로 따라간다. `$format` 이 `jpg` 면 JPEG 로, 아니면 WebP 로 만든다.
     *
     * @param  string  $absolutePath  원본 절대 경로
     * @param  int  $targetWidth  목표 가로
     * @param  string  $format  `webp` | `jpg`
     * @param  int  $quality  품질 (코어 `attachment.image_quality`)
     * @return string|null 인코딩 결과, 실패 시 null
     */
    public function encodeResized(string $absolutePath, int $targetWidth, string $format, int $quality): ?string
    {
        return $this->withImage($absolutePath, function (\Imagick $imagick) use ($targetWidth, $format, $quality): ?string {
            // 세로 0 = 가로 비율대로 (업스케일은 호출자가 이미 배제했다)
            $imagick->resizeImage($targetWidth, 0, \Imagick::FILTER_LANCZOS, 1, true);
            $imagick->stripImage();

            return $this->encode($imagick, $format, $quality);
        });
    }

    /**
     * 치수를 바꾸지 않고 WebP 로만 다시 인코딩한 바이트열을 반환합니다 (제자리 변환용).
     *
     * @return string|null 인코딩 결과, 실패 시 null
     */
    public function encodeSameSizeWebp(string $absolutePath, int $quality): ?string
    {
        return $this->withImage($absolutePath, function (\Imagick $imagick) use ($quality): ?string {
            $imagick->stripImage();

            return $this->encode($imagick, 'webp', $quality);
        });
    }

    /**
     * 이미지를 열어 콜백에 넘기고, 끝나면 반드시 해제합니다.
     *
     * @param  \Closure(\Imagick): (string|null)  $work
     */
    private function withImage(string $absolutePath, \Closure $work): ?string
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        $imagick = null;
        $startedAt = hrtime(true);

        try {
            $imagick = new \Imagick;
            $this->applyLimits($imagick);
            $imagick->readImage($absolutePath);

            // 다중 프레임(애니메이션 등)은 첫 프레임만 쓴다 — 대상 형식은 단일 프레임뿐이지만
            // 방어적으로 눌러 둔다.
            $imagick->setIteratorIndex(0);

            $result = $work($imagick);

            $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;
            if ($elapsed > self::MAX_WALL_SECONDS) {
                Log::warning('[g7-image-delivery] 1건 처리 시간 상한 초과', [
                    'path_tail' => basename($absolutePath),
                    'seconds' => round($elapsed, 1),
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('[g7-image-delivery] 이미지 인코딩 실패 (원본을 그대로 둡니다)', [
                'path_tail' => basename($absolutePath),
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            $imagick?->clear();
        }
    }

    /**
     * 열려 있는 이미지를 지정 포맷으로 직렬화합니다.
     */
    private function encode(\Imagick $imagick, string $format, int $quality): ?string
    {
        $quality = max(1, min(100, $quality));

        if (strtolower($format) === 'jpg') {
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality($quality);
            // 투명 배경을 JPEG 로 옮기면 검게 죽으므로 흰색으로 깐다.
            $imagick->setImageBackgroundColor(new \ImagickPixel('white'));
            $imagick = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality($quality);

            $blob = $imagick->getImageBlob();
            $imagick->clear();

            return $blob !== '' ? $blob : null;
        }

        $imagick->setImageFormat('webp');
        $imagick->setImageCompressionQuality($quality);
        $imagick->setOption('webp:method', '4');

        $blob = $imagick->getImageBlob();

        return $blob !== '' ? $blob : null;
    }

    /**
     * imagick 인스턴스에 자원 한도를 겁니다.
     */
    private function applyLimits(\Imagick $imagick): void
    {
        foreach ([
            \Imagick::RESOURCETYPE_MEMORY => self::LIMIT_MEMORY,
            \Imagick::RESOURCETYPE_MAP => self::LIMIT_MAP,
            \Imagick::RESOURCETYPE_DISK => self::LIMIT_DISK,
            \Imagick::RESOURCETYPE_AREA => self::LIMIT_AREA,
            \Imagick::RESOURCETYPE_WIDTH => self::LIMIT_DIMENSION,
            \Imagick::RESOURCETYPE_HEIGHT => self::LIMIT_DIMENSION,
            \Imagick::RESOURCETYPE_TIME => self::LIMIT_TIME,
        ] as $type => $limit) {
            try {
                $imagick->setResourceLimit($type, $limit);
            } catch (\Throwable) {
                // 이 빌드가 지원하지 않는 자원 종류는 건너뛴다 — 나머지 한도는 유효하다.
            }
        }
    }

    /**
     * 원본 픽셀 수가 상한을 넘는지 판정합니다.
     */
    public function exceedsSourcePixelCap(int $width, int $height): bool
    {
        return $width * $height > self::MAX_SOURCE_PIXELS;
    }

    /**
     * 변환본 계획이 WebP 치수 한계를 넘는지 (참고용 — 계산은 VariantPlan 이 한다).
     */
    public function exceedsWebpDimension(int $width, int $height): bool
    {
        return $width > VariantPlan::WEBP_MAX_DIMENSION || $height > VariantPlan::WEBP_MAX_DIMENSION;
    }
}
