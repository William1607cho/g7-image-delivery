<?php

namespace Plugins\G7\Image\Delivery;

use App\Extension\AbstractPlugin;
use Plugins\G7\Image\Delivery\Http\Middleware\RewriteBodyImagesExtension;
use Plugins\G7\Image\Delivery\Support\VariantPath;

/**
 * 본문 이미지 전송 (g7-image-delivery)
 *
 * 글·댓글 본문에 들어 있는 **에디터 업로드 이미지**를 방문자에게 더 적은 바이트로
 * 보내기 위한 확장이다. 세 가지 일을 한다.
 *
 * 1. **중간 크기 변환본** — 원본을 가로 960·1600 으로 줄인 WebP 를 미리 만들어 두고
 *    `srcset` 으로 내보낸다. 생성은 artisan 명령과 스케줄러만 한다(요청 시점 생성 없음).
 * 2. **제자리 변환** — PNG·JPEG 원본을 WebP 로 다시 인코딩한다. **해시를 유지**하므로
 *    글·댓글 본문은 한 글자도 바뀌지 않는다. artisan 전용이고 백업·되돌리기가 따라온다.
 * 3. **응답 가공** — 글 상세·댓글 목록 API 응답에서 대상 img 태그만 다시 써
 *    `srcset`·`sizes`·`width`·`height`·`loading`·`decoding` 을 붙이고 원본 링크로 감싼다.
 *
 * ## 되돌리기
 *
 * 이 확장을 비활성화하면 미들웨어가 등록 대상에서 빠지므로(`ExtensionMiddlewareRegistry`
 * 는 활성 확장만 수집한다) 응답이 **설치 전과 같아진다.** 본문 DB 도 원본 해시도 손대지
 * 않았기 때문이다. (제자리 변환만은 파일을 실제로 바꾸므로 별도 되돌리기 명령을 쓴다.)
 *
 * ## 알려진 제약
 *
 * `fetchpriority="high"` 는 방문자 화면(React) 에서 **템플릿의 DOMPurify 가 제거한다** —
 * DOMPurify 3.4.14 의 기본 허용 속성 목록에 이 속성이 없다. 봇/SSR 화면에서는 살아남는다.
 * 방문자 화면까지 살리려면 템플릿 쪽에서 `ADD_ATTR` 에 `fetchpriority` 를 더해야 하며,
 * 그건 이 확장 바깥의 일이다. 속성 자체는 표준대로 내보낸다.
 *
 * ## 도구
 *
 * 이미지 처리는 전부 **imagick** 으로 한다. 이 환경의 GD 는 번들 빌드라 WebP 인코더가
 * 없고(코어 `App\Support\ImageResizer` 가 GD 하드코딩이라 재사용할 수 없는 것도 같은 이유),
 * imagick 은 `pingImage()` 로 디코드 전에 치수만 읽을 수 있어 압축 폭탄 방어도 싸게 된다.
 */
class Plugin extends AbstractPlugin
{
    /**
     * 플러그인 메타데이터
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return [
            'author' => 'William Cho',
            'category' => 'performance',
        ];
    }

    /**
     * 설정 스키마
     *
     * 기능 단위 온오프만 둔다 — 폭·품질·한도처럼 잘못 만지면 조용히 나빠지는 값은
     * 노출하지 않는다(품질은 코어 `attachment.image_quality` 를 따른다).
     *
     * @return array<string, mixed>
     */
    public function getSettingsSchema(): array
    {
        return [
            'enabled' => $this->booleanSetting(
                true,
                ['ko' => '본문 이미지 가공 사용', 'en' => 'Enable Body Image Rewriting'],
                [
                    'ko' => '끄면 글·댓글 응답을 가공하지 않습니다. 본문과 원본 파일은 어느 쪽이든 바뀌지 않습니다.',
                    'en' => 'When off, post and comment responses are left untouched. Post bodies and original files are unchanged either way.',
                ],
            ),
            'variants_enabled' => $this->booleanSetting(
                true,
                ['ko' => '중간 크기 변환본 생성·정리', 'en' => 'Generate and Prune Resized Variants'],
                [
                    'ko' => '스케줄러가 미생성 변환본을 소량씩 만들고, 원본이 삭제된 변환본을 매일 정리합니다. 끄면 스케줄 작업만 멈추고 이미 만들어진 변환본은 계속 제공됩니다.',
                    'en' => 'The scheduler builds missing variants in small batches and prunes variants whose original was deleted. Turning this off only stops the scheduled work; variants already built keep being served.',
                ],
            ),
            'wrap_in_link' => $this->booleanSetting(
                true,
                ['ko' => '이미지를 원본 링크로 감싸기', 'en' => 'Wrap Images in a Link to the Original'],
                [
                    'ko' => '본문 이미지를 눌렀을 때 원본이 새 창에서 열리도록 링크로 감쌉니다. 이미 링크 안에 있는 이미지는 감싸지 않습니다.',
                    'en' => 'Wraps body images in a link so clicking one opens the original in a new tab. Images already inside a link are left as they are.',
                ],
            ),
        ];
    }

    /**
     * 설정 기본값
     *
     * @return array<string, mixed>
     */
    public function getConfigValues(): array
    {
        return [
            'enabled' => true,
            'variants_enabled' => true,
            'wrap_in_link' => true,
        ];
    }

    /**
     * 스케줄 작업
     *
     * 변환본 생성은 10분마다 소량씩 — 한 번에 몰아 돌리면 큰 원본에서 CPU 가 튄다.
     * 정리는 하루 한 번이면 충분하다(공개 경로가 요청 시점에 원본 존재를 이미 확인한다).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSchedules(): array
    {
        return [
            [
                'command' => 'g7-image-delivery:build-variants --scheduled --limit=20',
                'schedule' => '*/10 * * * *',
                'description' => '중간 크기 변환본을 미생성분부터 소량씩 생성',
                'enabled_config' => 'variants_enabled',
            ],
            [
                'command' => 'g7-image-delivery:prune-variants --scheduled',
                'schedule' => 'daily',
                'description' => '원본이 삭제된 변환본 정리',
                'enabled_config' => 'variants_enabled',
            ],
        ];
    }

    /**
     * 등록할 미들웨어
     *
     * 글 상세 응답에는 댓글이 이미 실려 있으므로(`data.comments[*]`) 두 라우트 모두 건다.
     * 코어 게이트(`ExtensionMiddlewareGate`)가 요청 라우트명을 `targets` 와 대조해
     * 맞을 때만 실행하므로, 다른 API 응답에는 영향이 없다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMiddleware(): array
    {
        return [
            [
                'class' => RewriteBodyImagesExtension::class,
                'groups' => ['api'],
                'timing' => 'after_core',
                'targets' => [
                    'api.modules.sirsoft-board.boards.posts.show',
                    'api.modules.sirsoft-board.boards.posts.comments.index',
                ],
            ],
        ];
    }

    /**
     * 제거 시 정리
     *
     * 변환본과 백업은 이 플러그인이 만든 파일이므로 함께 지운다. **원본은 건드리지 않는다** —
     * 제자리 변환을 한 상태라면 그건 되돌리기 명령으로 먼저 되돌려야 한다.
     */
    public function uninstall(): bool
    {
        $storage = new \App\Extension\Storage\PluginStorageDriver(
            VariantPath::IDENTIFIER,
            config('filesystems.disks.plugins') !== null ? 'plugins' : 'local'
        );

        $storage->deleteAll(VariantPath::CATEGORY);

        return true;
    }

    /**
     * 불리언 설정 한 줄을 만듭니다.
     *
     * @param  array<string, string>  $label
     * @param  array<string, string>  $hint
     * @return array<string, mixed>
     */
    private function booleanSetting(bool $default, array $label, array $hint): array
    {
        return [
            'type' => 'boolean',
            'default' => $default,
            'label' => $label,
            'hint' => $hint,
            'required' => false,
        ];
    }
}
