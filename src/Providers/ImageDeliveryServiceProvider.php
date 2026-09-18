<?php

namespace Plugins\G7\Image\Delivery\Providers;

use Illuminate\Support\ServiceProvider;
use Plugins\G7\Image\Delivery\Console\Commands\BuildVariantsCommand;
use Plugins\G7\Image\Delivery\Console\Commands\ConvertOriginalsCommand;
use Plugins\G7\Image\Delivery\Console\Commands\PruneVariantsCommand;
use Plugins\G7\Image\Delivery\Console\Commands\RevertOriginalsCommand;

/**
 * g7-image-delivery 서비스 프로바이더.
 *
 * 코어의 `PluginServiceProvider` 가 `plugins/&#42;/src/Providers/&#42;ServiceProvider.php` 를
 * 자동으로 발견해 등록한다 — 별도 선언이 필요 없다.
 */
class ImageDeliveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                BuildVariantsCommand::class,
                ConvertOriginalsCommand::class,
                RevertOriginalsCommand::class,
                PruneVariantsCommand::class,
            ]);
        }
    }
}
