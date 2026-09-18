<?php

namespace Plugins\G7\Image\Delivery\Services;

use App\Extension\Storage\PluginStorageDriver;
use Illuminate\Support\Facades\Storage;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;

/**
 * 에디터 업로드 원본(`sirsoft-ckeditor5`)의 행과 실제 파일 위치를 찾아 준다.
 *
 * `sirsoft-ckeditor5` 는 삭제 훅을 내보내지 않고(업로드 훅 3종뿐) 경로 규약도
 * 자기 서비스 안에 있으므로, 이 클래스가 그 규약을 한곳에 모아 둔다 —
 * 규약이 바뀌면 여기만 고치면 된다.
 *
 * `file_path` 는 `"{카테고리}/{나머지}"` 형태다(예: `images/2026/09/16/{uuid}.png`).
 * 스토리지 루트는 `{디스크}/{플러그인 식별자}/{카테고리}/…` 이므로 절대 경로는
 * `disk.path("sirsoft-ckeditor5/" . file_path)` 가 된다.
 */
class UploadLocator
{
    /** 원본을 소유한 플러그인 식별자. */
    public const OWNER = 'sirsoft-ckeditor5';

    /**
     * 원본 파일의 절대 경로를 반환합니다.
     */
    public function absolutePath(Ckeditor5ImageUpload $upload): ?string
    {
        $disk = $this->diskFor($upload);

        if ($disk === null) {
            return null;
        }

        return Storage::disk($disk)->path(self::OWNER.'/'.ltrim((string) $upload->file_path, '/'));
    }

    /**
     * 행에 기록된 디스크가 실제로 설정에 있으면 그것을, 없으면 기본값을 돌려줍니다.
     *
     * (`ImageServeService` 가 쓰는 고아 디스크 방어와 같은 판정이다.)
     */
    public function diskFor(Ckeditor5ImageUpload $upload): ?string
    {
        $rowDisk = (string) ($upload->storage_disk ?? '');

        if ($rowDisk !== '' && config("filesystems.disks.{$rowDisk}") !== null) {
            return $rowDisk;
        }

        return config('filesystems.disks.plugins') !== null ? 'plugins' : null;
    }

    /**
     * 원본 소유 플러그인의 스토리지 드라이버를 반환합니다 (제자리 변환 시 쓰기용).
     */
    public function ownerStorage(Ckeditor5ImageUpload $upload): ?PluginStorageDriver
    {
        $disk = $this->diskFor($upload);

        return $disk === null ? null : new PluginStorageDriver(self::OWNER, $disk);
    }

    /**
     * `file_path` 를 [카테고리, 나머지 경로] 로 나눕니다.
     *
     * @return array{0: string, 1: string}|null
     */
    public function splitPath(Ckeditor5ImageUpload $upload): ?array
    {
        [$category, $relative] = array_pad(explode('/', (string) $upload->file_path, 2), 2, '');

        if ($category === '' || $relative === '') {
            return null;
        }

        return [$category, $relative];
    }

    /**
     * 해시로 원본 행을 찾습니다.
     */
    public function findByHash(string $hash): ?Ckeditor5ImageUpload
    {
        return Ckeditor5ImageUpload::query()->where('hash', $hash)->first();
    }

    /**
     * 주어진 해시들 중 **실제로 존재하는** 것만 돌려줍니다.
     *
     * @param  list<string>  $hashes
     * @return array<string, Ckeditor5ImageUpload>
     */
    public function findManyByHash(array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        return Ckeditor5ImageUpload::query()
            ->whereIn('hash', $hashes)
            ->get()
            ->keyBy('hash')
            ->all();
    }
}
