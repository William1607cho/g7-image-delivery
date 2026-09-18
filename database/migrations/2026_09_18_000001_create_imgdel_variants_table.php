<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 본문 이미지 중간 크기 변환본 목록.
 *
 * 한 원본(에디터 업로드 해시)당 공칭 폭별로 최대 한 행. 실제 파일은 플러그인 스토리지
 * (`storage/app/plugins/g7-image-delivery/variants/…`)에 있고 이 표는 그 색인이다.
 *
 * 테이블명에 `g7_` 를 **직접 붙이지 않는다** — 코어가 `DB_PREFIX` 로 이미 붙이므로
 * 여기에 또 쓰면 `g7_g7_…` 가 된다(이 사이트에 실제 사례가 있다).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imgdel_variants', function (Blueprint $table) {
            $table->id()->comment('고유 ID');
            $table->char('upload_hash', 12)->comment('원본 에디터 업로드 해시 (12자 16진)');
            $table->unsignedSmallInteger('width')->comment('공칭 폭 (960 / 1600)');
            $table->string('format', 8)->comment('변환본 포맷 (webp / jpg)');
            $table->char('token', 8)->comment('URL 버전 토큰 — 재생성 시 바뀌어 주소가 달라진다');
            $table->string('path', 255)->comment('플러그인 스토리지 기준 상대 경로');
            $table->unsignedBigInteger('byte_size')->comment('변환본 바이트 수');
            $table->unsignedInteger('src_width')->comment('원본 가로');
            $table->unsignedInteger('src_height')->comment('원본 세로');
            $table->unsignedInteger('out_width')->comment('변환본 실제 가로');
            $table->unsignedInteger('out_height')->comment('변환본 실제 세로');
            $table->timestamp('generated_at')->comment('생성 시각');
            $table->timestamps();

            // 인덱스명을 명시한다 — 자동 생성명은 prefix 가 섞여 길이·중복 문제가 난다.
            $table->unique(['upload_hash', 'width'], 'imgdel_variants_hash_width_uq');
            $table->index('upload_hash', 'imgdel_variants_hash_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('imgdel_variants', function (Blueprint $table) {
                $table->comment('본문 이미지 중간 크기 변환본 색인');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('imgdel_variants');
    }
};
