<?php

use Illuminate\Support\Facades\Route;
use Plugins\G7\Image\Delivery\Http\Controllers\VariantServeController;

/*
 * g7-image-delivery API 라우트
 *
 * URL prefix: /api/plugins/g7-image-delivery (PluginRouteServiceProvider 자동 적용)
 */

// 변환본 제공 (공개). 파라미터 제약을 라우트에서 먼저 좁힌다 —
//  - 해시는 12자 16진 (sirsoft-ckeditor5 의 생성 규칙과 정확히 같다)
//  - 폭은 허용 목록 두 개뿐, 그 밖은 라우트가 매칭되지 않아 404
//  - 토큰은 8자 16진
// 파일은 미리 만들어 둔 것만 내보내므로 요청당 비용이 고정이다.
Route::get('variants/{hash}-{width}-{token}.{extension}', [VariantServeController::class, 'serve'])
    ->where('hash', '[a-f0-9]{12}')
    ->where('width', '960|1600')
    ->where('token', '[a-f0-9]{8}')
    ->where('extension', 'webp|jpg')
    ->middleware('throttle:600,1')
    ->name('api.g7-image-delivery.variants.serve');
