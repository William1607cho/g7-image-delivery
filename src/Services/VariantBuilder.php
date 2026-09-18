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
        $result = [
            'scanned' => 0, 'built' => 0, 'skipped' => 0, 'failed' => 0,
            'already_marked' => 0, 'newly_marked' => 0, 'items' => [],
        ];

        if (! $this->processor->isUsable()) {
            $result['items'][] = ['status' => 'aborted', 'reason' => 'imagick_unavailable'];

            return $result;
        }

        $quality = $this->quality();
        $result['already_marked'] = $this->markedCount();

        foreach ($this->pendingUploads($limit) as $upload) {
            $result['scanned']++;
            $outcome = $this->buildFor($upload, $quality, $dryRun);

            $result['items'][] = $outcome;

            match ($outcome['status']) {
                'built', 'would_build' => $result['built']++,
                'failed' => $result['failed']++,
                default => $result['skipped']++,
            };

            // 결론이 달라지지 않을 사유만 표식으로 남겨 다음 실행의 후보에서 뺀다.
            $reason = $outcome['reason'] ?? null;
            if ($reason !== null && in_array($reason, self::PERMANENT_SKIP_REASONS, true)) {
                $result['newly_marked']++;

                if (! $dryRun) {
                    [$w, $h] = array_pad(
                        array_map('intval', explode('x', (string) ($outcome['src'] ?? ''))), 2, 0
                    );
                    $this->mark($upload, $reason, $w, $h);
                }
            }
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
                    'status' => ImageVariant::STATUS_READY,
                    'skip_reason' => null,
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
                    'src_path' => (string) $upload->file_path,
                    'src_bytes' => (int) $upload->file_size,
                    'src_mime' => (string) $upload->mime_type,
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
                    'kind' => $variant->status === ImageVariant::STATUS_SKIPPED ? '표식' : '변환본',
                    'status' => $dryRun ? 'would_delete' : 'deleted',
                ];

                if ($dryRun) {
                    continue;
                }

                // 표식 행에는 대응하는 파일이 없다 — 행만 지운다.
                if ($variant->status !== ImageVariant::STATUS_SKIPPED) {
                    $this->storage()->delete(VariantPath::CATEGORY, $variant->path);
                    $this->storage()->deleteDirectory(
                        VariantPath::CATEGORY, VariantPath::relativeDirectory($variant->upload_hash)
                    );
                }

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
     * 후보에서 빠지는 경우는 둘이다.
     *
     *  1. **실제 변환본 행이 있다** (`status = ready`) — 이미 만들었다.
     *  2. **유효한 표식 행이 있다** (`status = skipped`) — 만들 필요가 없다고 이미 판정했다.
     *
     * 2번이 이 버전에서 더해진 부분이다. 표식이 없던 v0.1.0 은 "변환본이 안 생기는 원본"
     * (가로가 공칭 폭보다 좁은 경우 등)을 매 실행마다 다시 뽑았고, 그런 원본이 id 순으로
     * `--limit` 개 이상 연달아 있으면 배치가 거기서 영구히 멈췄다.
     *
     * 표식은 **그때 적어 둔 `file_path`·`file_size`·`mime_type` 이 지금 값과 모두 같을 때만**
     * 인정한다. 해시는 제자리 변환 뒤에도 그대로라 해시만으로는 원본이 바뀐 것을 알 수 없기
     * 때문이다. 셋 중 하나라도 달라지면 표식은 효력을 잃고 원본이 다시 후보가 된다.
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
                    ->whereColumn($table.'.upload_hash', 'ckeditor5_image_uploads.hash')
                    ->where(function ($outer) use ($table) {
                        $outer->where($table.'.status', ImageVariant::STATUS_READY)
                            ->orWhere(function ($marker) use ($table) {
                                $marker->where($table.'.status', ImageVariant::STATUS_SKIPPED)
                                    ->whereColumn($table.'.src_path', 'ckeditor5_image_uploads.file_path')
                                    ->whereColumn($table.'.src_bytes', 'ckeditor5_image_uploads.file_size')
                                    ->whereColumn($table.'.src_mime', 'ckeditor5_image_uploads.mime_type');
                            });
                    });
            })
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * 지금 유효한 표식 행의 수 (dry-run 보고용).
     */
    public function markedCount(): int
    {
        $table = (new ImageVariant)->getTable();

        return Ckeditor5ImageUpload::query()
            ->whereExists(function ($query) use ($table) {
                $query->selectRaw('1')
                    ->from($table)
                    ->whereColumn($table.'.upload_hash', 'ckeditor5_image_uploads.hash')
                    ->where($table.'.status', ImageVariant::STATUS_SKIPPED)
                    ->whereColumn($table.'.src_path', 'ckeditor5_image_uploads.file_path')
                    ->whereColumn($table.'.src_bytes', 'ckeditor5_image_uploads.file_size')
                    ->whereColumn($table.'.src_mime', 'ckeditor5_image_uploads.mime_type');
            })
            ->count();
    }

    /**
     * "변환본을 만들 필요가 없다" 표식을 남깁니다.
     *
     * **영구 사유에만** 남긴다 — 같은 파일을 다시 검사해도 결론이 달라지지 않는 경우다.
     * 파일이 잠깐 없거나(복구될 수 있다) 자원 한도에 걸려 실패한 경우는 남기지 않는다.
     * 그런 원본은 다음 실행에서 다시 시도된다.
     */
    private function mark(Ckeditor5ImageUpload $upload, string $reason, int $srcWidth = 0, int $srcHeight = 0): void
    {
        ImageVariant::query()->updateOrCreate(
            ['upload_hash' => $upload->hash, 'width' => 0],
            [
                'status' => ImageVariant::STATUS_SKIPPED,
                'skip_reason' => $reason,
                'format' => '',
                'token' => '',
                'path' => '',
                'byte_size' => 0,
                'src_width' => $srcWidth,
                'src_height' => $srcHeight,
                'out_width' => 0,
                'out_height' => 0,
                'src_path' => (string) $upload->file_path,
                'src_bytes' => (int) $upload->file_size,
                'src_mime' => (string) $upload->mime_type,
                'generated_at' => Carbon::now(),
            ]
        );
    }

    /**
     * 표식을 남기는 영구 사유 목록.
     *
     * 여기 없는 사유(파일 없음·ping 실패·인코딩 실패)는 일시적일 수 있어 표식하지 않는다.
     */
    private const PERMANENT_SKIP_REASONS = [
        'no_downscale_needed',
        'source_pixel_cap',
        'gif_not_targeted',
    ];

    private function variantExists(string $hash, int $width): bool
    {
        return ImageVariant::query()
            ->where('upload_hash', $hash)
            ->where('width', $width)
            ->where('status', ImageVariant::STATUS_READY)
            ->exists();
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
