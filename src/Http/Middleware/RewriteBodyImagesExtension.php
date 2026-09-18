<?php

namespace Plugins\G7\Image\Delivery\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Plugins\G7\Image\Delivery\Models\ImageVariant;
use Plugins\G7\Image\Delivery\Support\BodyImageRewriter;
use Plugins\G7\Image\Delivery\Support\VariantPath;
use Plugins\G7\Image\Delivery\Support\VariantPlan;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;

/**
 * 글 상세·댓글 목록 응답의 본문 HTML 에서 에디터 업로드 img 를 다시 쓴다.
 *
 * 붙는 곳은 `plugin.php::getMiddleware()` 의 `targets` 두 개뿐이다:
 *  - `api.modules.sirsoft-board.boards.posts.show`
 *  - `api.modules.sirsoft-board.boards.posts.comments.index`
 *
 * 글 상세 응답에는 댓글이 이미 실려 있으므로(`data.comments[*]`) 두 라우트 모두 필요하다.
 *
 * ## 바이트 보존
 *
 * JSON 은 **원본 응답이 쓴 인코딩 옵션 그대로**(`getEncodingOptions()`) 되쓰고,
 * HTML 은 `BodyImageRewriter` 가 대상 img 태그만 갈아끼운다. 그래서 우리가 손대기로 한
 * 곳 말고는 바이트가 그대로다. 바꿀 것이 하나도 없으면 응답 객체를 **아예 건드리지 않는다.**
 */
class RewriteBodyImagesExtension
{
    /** 본문에 이 조각이 없으면 볼 것도 없다. */
    private const MARKER = '/api/plugins/sirsoft-ckeditor5/images/';

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        if (! plugin_setting(VariantPath::IDENTIFIER, 'enabled', true)) {
            return $response;
        }

        try {
            $data = $response->getData(true);

            if (! is_array($data)) {
                return $response;
            }

            $hashes = [];
            $this->collectHashes($data, $hashes);

            if ($hashes === []) {
                return $response;
            }

            $rewriter = new BodyImageRewriter(
                $this->variantsFor($hashes),
                (bool) plugin_setting(VariantPath::IDENTIFIER, 'wrap_in_link', true),
                VariantPlan::SIZES,
            );

            $changed = false;
            $firstUsed = false;
            $rewritten = $this->walk($data, $rewriter, '', $changed, $firstUsed);

            if (! $changed) {
                return $response;
            }

            // setData 는 이 응답이 이미 쥐고 있는 인코딩 옵션(여기서는
            // JSON_UNESCAPED_UNICODE)으로 되쓴다 — 우리가 손대지 않은 곳은 바이트가 같다.
            $response->setData($rewritten);

            return $response;
        } catch (\Throwable $e) {
            // 가공 실패가 글 조회 자체를 막으면 안 된다 — 원본 응답을 그대로 내보낸다.
            Log::warning('[g7-image-delivery] 본문 이미지 가공 실패 (원본 응답을 그대로 내보냅니다)', [
                'route' => optional($request->route())->getName(),
                'error' => $e->getMessage(),
            ]);

            return $response;
        }
    }

    /**
     * 응답 안에서 대상 해시를 모읍니다 (변환본을 한 번에 조회하기 위해).
     *
     * @param  list<string>  $hashes  (참조)
     */
    private function collectHashes(mixed $node, array &$hashes): void
    {
        if (is_array($node)) {
            foreach ($node as $child) {
                $this->collectHashes($child, $hashes);
            }

            return;
        }

        if (! $this->looksLikeBody($node)) {
            return;
        }

        if (preg_match_all('#/api/plugins/sirsoft-ckeditor5/images/([a-f0-9]{12})#i', $node, $matches)) {
            foreach ($matches[1] as $hash) {
                $hash = strtolower($hash);

                if (! in_array($hash, $hashes, true)) {
                    $hashes[] = $hash;
                }
            }
        }
    }

    /**
     * 응답을 훑으며 본문 HTML 문자열만 가공합니다.
     *
     * `fetchpriority="high"` 는 **경로가 정확히 `data.content` 일 때만** 켠다 — 글 본문의
     * 첫 이미지 하나뿐이다. 댓글·부모글·답글 본문에는 붙이지 않는다.
     *
     * @param  string  $path  현재 키 경로 (예: `data.comments.0.content`)
     */
    private function walk(
        mixed $node,
        BodyImageRewriter $rewriter,
        string $path,
        bool &$changed,
        bool &$firstUsed
    ): mixed {
        if (is_array($node)) {
            foreach ($node as $key => $child) {
                $childPath = $path === '' ? (string) $key : $path.'.'.$key;
                $node[$key] = $this->walk($child, $rewriter, $childPath, $changed, $firstUsed);
            }

            return $node;
        }

        if (! $this->looksLikeBody($node)) {
            return $node;
        }

        $markFirst = ($path === 'data.content') && ! $firstUsed;
        $result = $rewriter->rewrite($node, $markFirst);

        if ($result !== $node) {
            $changed = true;

            if ($markFirst) {
                $firstUsed = true;
            }
        }

        return $result;
    }

    /**
     * 가공 대상 문자열인지 — 마커와 `<img` 를 **둘 다** 가진 문자열만 본다.
     *
     * 마커만 우연히 든 비-HTML 값(로그·설명 문자열 등)을 건드리지 않기 위한 이중 조건이다.
     */
    private function looksLikeBody(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
            && str_contains($value, self::MARKER)
            && stripos($value, '<img') !== false;
    }

    /**
     * 해시별 변환본 정보를 조립합니다.
     *
     * @param  list<string>  $hashes
     * @return array<string, array{src: string, srcset: string, width: int, height: int}>
     */
    private function variantsFor(array $hashes): array
    {
        $rows = ImageVariant::query()
            ->whereIn('upload_hash', $hashes)
            ->orderBy('width')
            ->get()
            ->groupBy('upload_hash');

        if ($rows->isEmpty()) {
            return [];
        }

        // 원본을 srcset 후보로 넣을지 판정하려면 원본 MIME 이 필요하다.
        $mimes = Ckeditor5ImageUpload::query()
            ->whereIn('hash', $rows->keys()->all())
            ->pluck('mime_type', 'hash')
            ->all();

        $out = [];

        foreach ($rows as $hash => $variants) {
            $base = $variants->firstWhere('width', VariantPlan::NOMINAL_WIDTHS[0]) ?? $variants->first();

            if ($base === null) {
                continue;
            }

            $candidates = [];

            foreach ($variants as $variant) {
                $candidates[] = $variant->publicUrl().' '.$variant->out_width.'w';
            }

            $originalWidth = VariantPlan::originalCandidateWidth(
                (int) $base->src_width,
                (string) ($mimes[$hash] ?? '')
            );

            if ($originalWidth !== null) {
                $candidates[] = VariantPath::originalUrl($hash).' '.$originalWidth.'w';
            }

            $out[$hash] = [
                'src' => $base->publicUrl(),
                'srcset' => implode(', ', $candidates),
                'width' => (int) $base->out_width,
                'height' => (int) $base->out_height,
            ];
        }

        return $out;
    }
}
