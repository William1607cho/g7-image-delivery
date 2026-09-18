<?php

namespace Plugins\G7\Image\Delivery\Support;

/**
 * 변환본의 저장 경로·공개 URL·버전 토큰을 만드는 순수 클래스.
 *
 * 파일 시스템에 접근하지 않는다 — 문자열 계산만 한다. 단위 테스트 대상.
 */
final class VariantPath
{
    /**
     * 플러그인 식별자 (URL prefix 와 스토리지 루트에 함께 쓰인다).
     */
    public const IDENTIFIER = 'g7-image-delivery';

    /**
     * 플러그인 스토리지 안의 변환본 카테고리 디렉토리.
     */
    public const CATEGORY = 'variants';

    /**
     * format → 파일 확장자.
     */
    private const EXTENSIONS = [
        'webp' => 'webp',
        'jpg' => 'jpg',
    ];

    /**
     * format → 응답 Content-Type.
     */
    private const CONTENT_TYPES = [
        'webp' => 'image/webp',
        'jpg' => 'image/jpeg',
    ];

    /**
     * format 의 파일 확장자를 반환합니다.
     */
    public static function extensionFor(string $format): string
    {
        return self::EXTENSIONS[strtolower($format)] ?? 'webp';
    }

    /**
     * format 의 Content-Type 을 반환합니다.
     */
    public static function contentTypeFor(string $format): string
    {
        return self::CONTENT_TYPES[strtolower($format)] ?? 'image/webp';
    }

    /**
     * 버전 토큰을 만듭니다.
     *
     * 재생성하면 생성 시각·바이트 수가 달라지므로 토큰이 바뀌고, 토큰이 URL 에 들어가므로
     * 주소 자체가 바뀐다 → 1년 immutable 캐시를 안전하게 붙일 수 있다.
     *
     * @param  string  $hash  원본 업로드 해시 (12자)
     * @param  int  $width  공칭 폭
     * @param  string  $format  변환본 포맷
     * @param  int  $generatedAt  생성 시각 (unix)
     * @param  int  $bytes  변환본 바이트 수
     * @return string 8자 16진 토큰
     */
    public static function token(string $hash, int $width, string $format, int $generatedAt, int $bytes): string
    {
        return substr(sha1($hash.'|'.$width.'|'.strtolower($format).'|'.$generatedAt.'|'.$bytes), 0, 8);
    }

    /**
     * 플러그인 스토리지 기준 상대 경로를 반환합니다.
     *
     * 해시 앞 2자로 한 단계 나눠 한 디렉토리에 파일이 몰리지 않게 한다.
     *
     * @return string `{앞2}/{해시}/{폭}.{확장자}`
     */
    public static function relativePath(string $hash, int $width, string $format): string
    {
        return substr($hash, 0, 2).'/'.$hash.'/'.$width.'.'.self::extensionFor($format);
    }

    /**
     * 원본 해시 하나가 쓰는 디렉토리의 상대 경로를 반환합니다 (정리용).
     */
    public static function relativeDirectory(string $hash): string
    {
        return substr($hash, 0, 2).'/'.$hash;
    }

    /**
     * 공개 URL 을 반환합니다.
     *
     * 확장자로 끝나게 만든다 — Cloudflare 의 기본 캐시 판정이 확장자 기반이라,
     * 확장자 없는 주소(`…/images/{hash}`)와 달리 별도 규칙 없이 엣지 캐시에 태워진다.
     */
    public static function publicUrl(string $hash, int $width, string $token, string $format): string
    {
        return '/api/plugins/'.self::IDENTIFIER.'/'.self::CATEGORY.'/'
            .$hash.'-'.$width.'-'.$token.'.'.self::extensionFor($format);
    }

    /**
     * 원본(에디터 업로드) 이미지의 공개 URL 을 반환합니다.
     */
    public static function originalUrl(string $hash): string
    {
        return '/api/plugins/sirsoft-ckeditor5/images/'.$hash;
    }
}
