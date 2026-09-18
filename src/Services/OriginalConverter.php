<?php

namespace Plugins\G7\Image\Delivery\Services;

use App\Extension\Storage\PluginStorageDriver;
use Illuminate\Support\Facades\Log;
use Plugins\G7\Image\Delivery\Support\VariantPath;
use Plugins\G7\Image\Delivery\Support\VariantPlan;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;

/**
 * 에디터 업로드 **원본**을 제자리에서 WebP 로 바꾼다 (치수는 그대로).
 *
 * ## 해시를 바꾸지 않는다
 *
 * 본문 img 의 `src` 는 `…/images/{해시}` 이므로 **해시만 그대로 두면 글·댓글 본문을
 * 한 글자도 고치지 않아도 된다.** 그래서 파일과 몇몇 메타 컬럼만 갈아끼운다.
 *
 * ## 왜 DB 행도 같이 고쳐야 하는가
 *
 * `sirsoft-ckeditor5` 의 `ImageServeService` 는 응답 `Content-Type` 을 **파일이 아니라
 * DB `mime_type` 에서 읽고**, 다운로드 파일명으로 `original_name` 을 그대로 쓴다.
 * 파일만 바꾸면 WebP 바이트가 `image/png` 로 나가고 사용자에게는 `.png` 로 내려간다.
 *
 * ## 안전장치
 *
 * - artisan 전용. 업로드 훅도 자동 실행도 없다
 * - 기본이 `--dry-run` 이고 실제 변환에는 `--apply` 가 필요하다
 * - 변환 전에 원본 파일과 DB 행 스냅샷을 배치 폴더에 백업하고, 되돌리기 명령을 함께 둔다
 * - 결과가 원본보다 크거나 같으면 건너뛴다 (재인코딩이 역효과면 안 한다)
 */
class OriginalConverter
{
    /** 백업 루트 카테고리. */
    public const BACKUP_CATEGORY = 'backups';

    public function __construct(
        private readonly ImageProcessor $processor,
        private readonly UploadLocator $locator,
    ) {}

    /**
     * 제자리 변환을 수행합니다.
     *
     * @param  int  $limit  이번 회차 처리 상한
     * @param  bool  $dryRun  참이면 판정만 한다
     * @return array{batch: string|null, scanned: int, converted: int, skipped: int, failed: int, saved_bytes: int, items: list<array<string, mixed>>}
     */
    public function convert(int $limit, bool $dryRun = true): array
    {
        $result = [
            'batch' => null, 'scanned' => 0, 'converted' => 0,
            'skipped' => 0, 'failed' => 0, 'saved_bytes' => 0, 'items' => [],
        ];

        if (! $this->processor->isUsable()) {
            $result['items'][] = ['status' => 'aborted', 'reason' => 'imagick_unavailable'];

            return $result;
        }

        $quality = (int) config('attachment.image_quality', 85);
        $batch = $dryRun ? null : $this->newBatchId();
        $result['batch'] = $batch;
        $manifest = [];

        foreach ($this->candidates($limit) as $upload) {
            $result['scanned']++;
            $outcome = $this->convertOne($upload, $quality, $batch, $dryRun, $manifest);
            $result['items'][] = $outcome;

            match ($outcome['status']) {
                'converted', 'would_convert' => $result['converted']++,
                'failed' => $result['failed']++,
                default => $result['skipped']++,
            };

            $result['saved_bytes'] += (int) ($outcome['saved'] ?? 0);
        }

        if (! $dryRun && $manifest !== []) {
            $this->storage()->put(
                self::BACKUP_CATEGORY,
                $batch.'/manifest.json',
                (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }

        return $result;
    }

    /**
     * 원본 1건을 변환합니다.
     *
     * @param  list<array<string, mixed>>  $manifest  (참조) 백업 매니페스트 누적
     * @return array<string, mixed>
     */
    private function convertOne(
        Ckeditor5ImageUpload $upload,
        int $quality,
        ?string $batch,
        bool $dryRun,
        array &$manifest
    ): array {
        $line = ['hash' => $upload->hash, 'mime' => $upload->mime_type, 'bytes' => (int) $upload->file_size];

        $absolute = $this->locator->absolutePath($upload);

        if ($absolute === null || ! is_file($absolute)) {
            return $line + ['status' => 'skipped', 'reason' => 'file_missing'];
        }

        $size = $this->processor->ping($absolute);
        $width = $size['width'] ?? 0;
        $height = $size['height'] ?? 0;

        $skip = VariantPlan::inPlaceSkipReason((string) $upload->mime_type, $width, $height);

        if ($skip !== null) {
            return $line + ['status' => 'skipped', 'reason' => $skip, 'src' => $width.'x'.$height];
        }

        if ($this->processor->exceedsSourcePixelCap($width, $height)) {
            return $line + ['status' => 'skipped', 'reason' => 'source_pixel_cap', 'src' => $width.'x'.$height];
        }

        $originalBytes = (int) @filesize($absolute) ?: (int) $upload->file_size;

        if ($dryRun) {
            return $line + ['status' => 'would_convert', 'src' => $width.'x'.$height];
        }

        $blob = $this->processor->encodeSameSizeWebp($absolute, $quality);

        if ($blob === null || $blob === '') {
            return $line + ['status' => 'failed', 'reason' => 'encode_failed'];
        }

        if (strlen($blob) >= $originalBytes) {
            return $line + ['status' => 'skipped', 'reason' => 'no_gain', 'new_bytes' => strlen($blob)];
        }

        $split = $this->locator->splitPath($upload);
        $ownerStorage = $this->locator->ownerStorage($upload);

        if ($split === null || $ownerStorage === null) {
            return $line + ['status' => 'failed', 'reason' => 'path_unresolved'];
        }

        [$category, $relative] = $split;

        // 1) 백업 — 원본 바이트와 행 스냅샷을 먼저 남긴다.
        $originalContent = @file_get_contents($absolute);

        if ($originalContent === false) {
            return $line + ['status' => 'failed', 'reason' => 'read_failed'];
        }

        $backupName = $upload->hash.'.'.$this->extensionOf($relative);

        if (! $this->storage()->put(self::BACKUP_CATEGORY, $batch.'/'.$backupName, $originalContent)) {
            return $line + ['status' => 'failed', 'reason' => 'backup_failed'];
        }

        $snapshot = [
            'id' => (int) $upload->id,
            'hash' => (string) $upload->hash,
            'original_name' => (string) $upload->original_name,
            'file_path' => (string) $upload->file_path,
            'storage_disk' => (string) $upload->storage_disk,
            'file_size' => (int) $upload->file_size,
            'mime_type' => (string) $upload->mime_type,
            'backup_file' => $backupName,
        ];

        // 2) 새 파일을 쓴다 (같은 디렉토리, 확장자만 .webp).
        $newRelative = $this->swapExtension($relative, 'webp');

        if (! $ownerStorage->put($category, $newRelative, $blob)) {
            return $line + ['status' => 'failed', 'reason' => 'write_failed'];
        }

        // 3) DB 행을 갱신한다 — 해시는 건드리지 않는다.
        $upload->forceFill([
            'mime_type' => 'image/webp',
            'file_size' => strlen($blob),
            'file_path' => $category.'/'.$newRelative,
            'original_name' => $this->swapExtension((string) $upload->original_name, 'webp'),
        ])->save();

        // 4) 옛 파일을 지운다 (경로가 달라졌을 때만).
        if ($newRelative !== $relative) {
            $ownerStorage->delete($category, $relative);
        }

        $manifest[] = $snapshot;

        Log::info('[g7-image-delivery] 원본 제자리 변환', [
            'hash' => $upload->hash,
            'batch' => $batch,
            'from' => $snapshot['mime_type'],
            'saved' => $originalBytes - strlen($blob),
        ]);

        return $line + [
            'status' => 'converted',
            'src' => $width.'x'.$height,
            'new_bytes' => strlen($blob),
            'saved' => $originalBytes - strlen($blob),
        ];
    }

    /**
     * 배치 하나를 통째로 되돌립니다.
     *
     * @return array{batch: string, scanned: int, reverted: int, failed: int, items: list<array<string, mixed>>}
     */
    public function revert(string $batch, bool $dryRun = false): array
    {
        $result = ['batch' => $batch, 'scanned' => 0, 'reverted' => 0, 'failed' => 0, 'items' => []];

        $raw = $this->storage()->get(self::BACKUP_CATEGORY, $batch.'/manifest.json');

        if ($raw === null) {
            $result['items'][] = ['status' => 'aborted', 'reason' => 'manifest_missing'];

            return $result;
        }

        $manifest = json_decode($raw, true);

        if (! is_array($manifest)) {
            $result['items'][] = ['status' => 'aborted', 'reason' => 'manifest_unreadable'];

            return $result;
        }

        foreach ($manifest as $entry) {
            $result['scanned']++;
            $outcome = $this->revertOne($batch, (array) $entry, $dryRun);
            $result['items'][] = $outcome;

            match ($outcome['status']) {
                'reverted', 'would_revert' => $result['reverted']++,
                'failed' => $result['failed']++,
                default => null,
            };
        }

        return $result;
    }

    /**
     * 매니페스트 한 줄을 되돌립니다.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function revertOne(string $batch, array $entry, bool $dryRun): array
    {
        $hash = (string) ($entry['hash'] ?? '');
        $line = ['hash' => $hash];

        $upload = $this->locator->findByHash($hash);

        if ($upload === null) {
            return $line + ['status' => 'failed', 'reason' => 'upload_row_missing'];
        }

        $content = $this->storage()->get(self::BACKUP_CATEGORY, $batch.'/'.((string) ($entry['backup_file'] ?? '')));

        if ($content === null) {
            return $line + ['status' => 'failed', 'reason' => 'backup_missing'];
        }

        if ($dryRun) {
            return $line + ['status' => 'would_revert'];
        }

        $currentSplit = $this->locator->splitPath($upload);
        $ownerStorage = $this->locator->ownerStorage($upload);

        [$oldCategory, $oldRelative] = array_pad(
            explode('/', (string) ($entry['file_path'] ?? ''), 2), 2, ''
        );

        if ($ownerStorage === null || $oldCategory === '' || $oldRelative === '') {
            return $line + ['status' => 'failed', 'reason' => 'path_unresolved'];
        }

        if (! $ownerStorage->put($oldCategory, $oldRelative, $content)) {
            return $line + ['status' => 'failed', 'reason' => 'restore_write_failed'];
        }

        // 변환으로 생긴 webp 파일을 지운다 (경로가 달라졌을 때만).
        if ($currentSplit !== null && $currentSplit[1] !== $oldRelative) {
            $ownerStorage->delete($currentSplit[0], $currentSplit[1]);
        }

        $upload->forceFill([
            'mime_type' => (string) ($entry['mime_type'] ?? $upload->mime_type),
            'file_size' => (int) ($entry['file_size'] ?? $upload->file_size),
            'file_path' => (string) ($entry['file_path'] ?? $upload->file_path),
            'original_name' => (string) ($entry['original_name'] ?? $upload->original_name),
        ])->save();

        return $line + ['status' => 'reverted'];
    }

    /**
     * 변환 대상 후보 (WebP 가 아닌 에디터 업로드).
     *
     * @return \Illuminate\Support\Collection<int, Ckeditor5ImageUpload>
     */
    private function candidates(int $limit): \Illuminate\Support\Collection
    {
        return Ckeditor5ImageUpload::query()
            ->whereIn('mime_type', ['image/png', 'image/jpeg'])
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    private function newBatchId(): string
    {
        return date('Ymd-His').'-'.bin2hex(random_bytes(2));
    }

    private function extensionOf(string $path): string
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return $ext !== '' ? $ext : 'bin';
    }

    private function swapExtension(string $path, string $extension): string
    {
        if (! str_contains(basename($path), '.')) {
            return $path.'.'.$extension;
        }

        return (string) preg_replace('/\.[^.\/]+$/', '.'.$extension, $path);
    }

    private function storage(): PluginStorageDriver
    {
        $disk = config('filesystems.disks.plugins') !== null ? 'plugins' : 'local';

        return new PluginStorageDriver(VariantPath::IDENTIFIER, $disk);
    }

    /**
     * 백업 배치 목록 (되돌리기 대상 선택용).
     *
     * @return list<string>
     */
    public function listBatches(): array
    {
        $disk = config('filesystems.disks.plugins') !== null ? 'plugins' : 'local';
        $root = VariantPath::IDENTIFIER.'/'.self::BACKUP_CATEGORY;

        // PluginStorageDriver::files() 는 비재귀(`Storage::files`)라 배치 **하위 폴더**를
        // 보지 못한다. 배치는 디렉토리 단위이므로 디렉토리 목록을 직접 읽는다.
        $batches = [];

        foreach (\Illuminate\Support\Facades\Storage::disk($disk)->directories($root) as $directory) {
            $name = basename($directory);

            if ($name !== '') {
                $batches[] = $name;
            }
        }

        sort($batches);

        return $batches;
    }
}
