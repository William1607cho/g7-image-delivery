<?php

namespace Plugins\G7\Image\Delivery\Services;

use App\Extension\Storage\PluginStorageDriver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Plugins\G7\Image\Delivery\Models\ImageVariant;
use Plugins\G7\Image\Delivery\Support\VariantPath;
use Plugins\G7\Image\Delivery\Support\VariantPlan;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;

/**
 * 중간 크기 변환본을 만들고 지운다.
 *
 * 생성은 **요청 시점에 하지 않는다** — artisan 일괄 명령과 스케줄러만 부른다.
 * 공개 경로는 이미 만들어진 파일만 내보낸다.
 */
class VariantBuilder
{
    public function __construct(
        private readonly ImageProcessor $processor,
        private readonly UploadLocator $locator,
    ) {}

    /**
     * 변환본이 아직 없는 원본을 찾아 만듭니다.
     *
     * @param  int  $limit  이번 회차에 처리할 원본 수 상한
     * @param  bool  $dryRun  참이면 계획만 세우고 파일·DB 를 건드리지 않는다
     * @return array{scanned: int, built: int, skipped: int, failed: int, items: list<array<string, mixed>>}
     */
    public function buildPending(int $limit, bool $dryRun = false): array
    {
        $result = ['scanned' => 0, 'built' => 0, 'skipped' => 0, 'failed' => 0, 'items' => []];

        if (! $this->processor->isUsable()) {
            $result['items'][] = ['status' => 'aborted', 'reason' => 'imagick_unavailable'];

            return $result;
        }

        $quality = $this->quality();

        foreach ($this->pendingUploads($limit) as $upload) {
            $result['scanned']++;
            $outcome = $this->buildFor($upload, $quality, $dryRun);

            $result['items'][] = $outcome;

            match ($outcome['status']) {
                'built', 'would_build' => $result['built']++,
                'failed' => $result['failed']++,
                default => $result['skipped']++,
            };
        }

        return $result;
    }

    /**
     * 원본 1건의 변환본을 만듭니다.
     *
     * @return array<string, mixed> 처리 결과 한 줄
     */
    public function buildFor(Ckeditor5ImageUpload $upload, int $quality, bool $dryRun = false): array
    {
        $line = ['hash' => $upload->hash, 'mime' => $upload->mime_type];

        if (strtolower((string) $upload->mime_type) === 'image/gif') {
            // GIF 는 변환본을 만들지 않는다 (애니메이션 프레임 파괴).
            return $line + ['status' => 'skipped', 'reason' => 'gif_not_targeted'];
        }

        $absolute = $this->locator->absolutePath($upload);

        if ($absolute === null || ! is_file($absolute)) {
            return $line + ['status' => 'skipped', 'reason' => 'file_missing'];
        }

        $size = $this->processor->ping($absolute);

        if ($size === null) {
            return $line + ['status' => 'failed', 'reason' => 'ping_failed'];
        }

        if ($this->processor->exceedsSourcePixelCap($size['width'], $size['height'])) {
            return $line + [
                'status' => 'skipped',
                'reason' => 'source_pixel_cap',
                'src' => $size['width'].'x'.$size['height'],
            ];
        }

        $plans = VariantPlan::variantsFor($size['width'], $size['height']);

        if ($plans === []) {
            return $line + [
                'status' => 'skipped',
                'reason' => 'no_downscale_needed',
                'src' => $size['width'].'x'.$size['height'],
            ];
        }

        $made = [];

        foreach ($plans as $plan) {
            if ($this->variantExists($upload->hash, $plan['width'])) {
                continue;
            }

            if ($dryRun) {
                $made[] = $plan['width'].'→'.$plan['out_width'].'x'.$plan['out_height'].'.'.$plan['format'];

                continue;
            }

            $blob = $this->processor->encodeResized(
                $absolute, $plan['out_width'], $plan['out_height'], $plan['format'], $quality
            );

            if ($blob === null || $blob === '') {
                Log::warning('[g7-image-delivery] 변환본 인코딩 실패', [
                    'hash' => $upload->hash,
                    'width' => $plan['width'],
                ]);

                return $line + ['status' => 'failed', 'reason' => 'encode_failed', 'width' => $plan['width']];
            }

            $relative = VariantPath::relativePath($upload->hash, $plan['width'], $plan['format']);
            $generatedAt = Carbon::now();

            if (! $this->storage()->put(VariantPath::CATEGORY, $relative, $blob)) {
                return $line + ['status' => 'failed', 'reason' => 'write_failed', 'width' => $plan['width']];
            }

            ImageVariant::query()->updateOrCreate(
                ['upload_hash' => $upload->hash, 'width' => $plan['width']],
                [
                    'format' => $plan['format'],
                    'token' => VariantPath::token(
                        $upload->hash, $plan['width'], $plan['format'], $generatedAt->getTimestamp(), strlen($blob)
                    ),
                    'path' => $relative,
                    'byte_size' => strlen($blob),
                    'src_width' => $size['width'],
                    'src_height' => $size['height'],
                    'out_width' => $plan['out_width'],
                    'out_height' => $plan['out_height'],
                    'generated_at' => $generatedAt,
                ]
            );

            $made[] = $plan['width'].'→'.$plan['out_width'].'x'.$plan['out_height'].'.'.$plan['format'];
        }

        if ($made === []) {
            return $line + ['status' => 'skipped', 'reason' => 'already_built'];
        }

        return $line + [
            'status' => $dryRun ? 'would_build' : 'built',
            'src' => $size['width'].'x'.$size['height'],
            'variants' => implode(', ', $made),
        ];
    }

    /**
     * 원본 행이 사라진 변환본을 지웁니다.
     *
     * @return array{scanned: int, deleted: int, items: list<array<string, mixed>>}
     */
    public function pruneOrphans(bool $dryRun = false): array
    {
        $result = ['scanned' => 0, 'deleted' => 0, 'items' => []];

        ImageVariant::query()->orderBy('id')->chunkById(200, function ($variants) use (&$result, $dryRun) {
            $hashes = $variants->pluck('upload_hash')->unique()->values()->all();
            $alive = array_keys($this->locator->findManyByHash($hashes));

            foreach ($variants as $variant) {
                $result['scanned']++;

                if (in_array($variant->upload_hash, $alive, true)) {
                    continue;
                }

                $result['items'][] = [
                    'hash' => $variant->upload_hash,
                    'width' => $variant->width,
                    'status' => $dryRun ? 'would_delete' : 'deleted',
                ];

                if ($dryRun) {
                    continue;
                }

                $this->storage()->delete(VariantPath::CATEGORY, $variant->path);
                $this->storage()->deleteDirectory(
                    VariantPath::CATEGORY, VariantPath::relativeDirectory($variant->upload_hash)
                );
                $variant->delete();
                $result['deleted']++;
            }
        });

        return $result;
    }

    /**
     * 원본 1건의 변환본을 전부 지웁니다 (제자리 변환·되돌리기 후 재생성 유도).
     */
    public function forget(string $hash): void
    {
        $this->storage()->deleteDirectory(VariantPath::CATEGORY, VariantPath::relativeDirectory($hash));
        ImageVariant::query()->where('upload_hash', $hash)->delete();
    }

    /**
     * 변환본 파일의 절대 경로.
     */
    public function absolutePathOf(ImageVariant $variant): string
    {
        return Storage::disk($this->disk())
            ->path(VariantPath::IDENTIFIER.'/'.VariantPath::CATEGORY.'/'.$variant->path);
    }

    /**
     * 변환본을 아직 만들지 않은 원본 목록.
     *
     * "변환본 행이 하나도 없는 원본" 을 대상으로 한다 — 폭별 부분 생성은 buildFor 가 메운다.
     *
     * @return \Illuminate\Support\Collection<int, Ckeditor5ImageUpload>
     */
    private function pendingUploads(int $limit): \Illuminate\Support\Collection
    {
        $table = (new ImageVariant)->getTable();

        return Ckeditor5ImageUpload::query()
            ->whereNotIn('mime_type', ['image/gif'])
            ->whereNotExists(function ($query) use ($table) {
                $query->selectRaw('1')
                    ->from($table)
                    ->whereColumn($table.'.upload_hash', 'ckeditor5_image_uploads.hash');
            })
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    private function variantExists(string $hash, int $width): bool
    {
        return ImageVariant::query()->where('upload_hash', $hash)->where('width', $width)->exists();
    }

    private function storage(): PluginStorageDriver
    {
        return new PluginStorageDriver(VariantPath::IDENTIFIER, $this->disk());
    }

    private function disk(): string
    {
        return config('filesystems.disks.plugins') !== null ? 'plugins' : 'local';
    }

    /**
     * 코어 이미지 품질 설정값. 하드코딩하지 않는다.
     */
    public function quality(): int
    {
        return (int) config('attachment.image_quality', 85);
    }
}
