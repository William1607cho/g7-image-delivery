<?php

namespace Plugins\G7\Image\Delivery\Http\Controllers;

use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\G7\Image\Delivery\Models\ImageVariant;
use Plugins\G7\Image\Delivery\Services\UploadLocator;
use Plugins\G7\Image\Delivery\Services\VariantBuilder;
use Plugins\G7\Image\Delivery\Support\VariantPath;
use Plugins\G7\Image\Delivery\Support\VariantPlan;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 변환본 제공 — **이미 만들어져 있는 파일만** 내보낸다.
 *
 * 요청 시점에 생성하지 않는다. 생성은 artisan 일괄 명령과 스케줄러의 일이다.
 * 그래서 이 경로는 비용이 고정이고, 외부에서 임의의 폭·해시를 찔러도 CPU 를 쓰지 않는다.
 *
 * 404 를 주는 경우:
 *  - 변환본 행이나 파일이 없음
 *  - 폭이 허용 목록 밖 (라우트 `where` 에서 이미 걸리지만 한 번 더 검사한다)
 *  - 버전 토큰 불일치 (재생성으로 주소가 바뀐 뒤의 옛 주소)
 *  - **원본 행이 사라짐** — 원본이 지워지면 변환본도 그 즉시 죽는다
 */
class VariantServeController extends PublicBaseController
{
    public function __construct(
        private readonly UploadLocator $locator,
        private readonly VariantBuilder $builder,
    ) {}

    /**
     * 변환본 1건을 내보냅니다.
     *
     * @param  string  $hash  원본 해시 (12자 16진)
     * @param  string  $width  공칭 폭
     * @param  string  $token  버전 토큰
     * @param  string  $extension  파일 확장자
     */
    public function serve(string $hash, string $width, string $token, string $extension): BinaryFileResponse|JsonResponse
    {
        $width = (int) $width;

        if (! in_array($width, VariantPlan::NOMINAL_WIDTHS, true)) {
            return $this->missing();
        }

        $variant = ImageVariant::query()
            ->where('upload_hash', $hash)
            ->where('width', $width)
            ->first();

        if ($variant === null || ! hash_equals($variant->token, $token)) {
            return $this->missing();
        }

        if (VariantPath::extensionFor($variant->format) !== strtolower($extension)) {
            return $this->missing();
        }

        // 원본이 지워졌으면 파생물도 내보내지 않는다 (삭제 훅이 없어 실시간 연동이 불가하므로
        // 요청 시점 검사로 대신한다 — 디스크 회수는 매일 정리 명령이 맡는다).
        if ($this->locator->findByHash($hash) === null) {
            return $this->missing();
        }

        $absolute = $this->builder->absolutePathOf($variant);

        if (! is_file($absolute)) {
            return $this->missing();
        }

        return response()->file($absolute, [
            'Content-Type' => VariantPath::contentTypeFor($variant->format),
            // 토큰이 주소에 들어 있어 내용이 바뀌면 주소도 바뀐다 → immutable 이 안전하다.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * 존재를 드러내지 않는 404. 사유를 구분해 알려 주지 않는다.
     */
    private function missing(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Not Found'], 404);
    }
}
