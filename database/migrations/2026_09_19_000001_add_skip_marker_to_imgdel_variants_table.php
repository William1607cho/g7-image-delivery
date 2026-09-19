<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 변환본 표에 "생성 불필요" 표식을 담을 수 있게 한다.
 *
 * ## 왜 필요한가
 *
 * 후보 선정은 "변환본 행이 없는 원본" 을 id 순으로 `--limit` 만큼 뽑는다. 그런데 가로가
 * 공칭 폭보다 좁아 **변환본이 아예 생기지 않는 원본**은 행이 영영 만들어지지 않아 매 실행마다
 * 다시 뽑힌다. 그런 원본이 id 순으로 `--limit` 개 이상 연달아 있으면 그 배치는 "검사만 하고
 * 0건 생성" 으로 끝나고, 다음 배치도 같은 구간을 다시 본다 — **진행이 영구히 멈춘다.**
 * (blog 실측: 그런 원본 142건, 연속 최장 28건 > 배치 크기 20)
 *
 * 그래서 "이 원본은 변환본을 만들 필요가 없다" 를 표식 행으로 남겨 후보에서 빠지게 한다.
 * 부수 효과로 스케줄러가 10분마다 같은 파일들을 다시 여는 낭비도 사라진다.
 *
 * ## 표식 행의 모양
 *
 * `width = 0` 인 행 하나다. 공개 경로는 폭을 960·1600 으로만 받으므로 이 행은 서빙 대상이
 * 될 수 없고, 기존 `(upload_hash, width)` 유니크 제약과도 충돌하지 않는다.
 *
 * ## 무효화 근거
 *
 * 해시는 제자리 변환 뒤에도 그대로라(본문을 안 고치려는 설계) 해시만으로는 원본이 바뀐 것을
 * 알 수 없다. 그래서 표식을 남길 때 그 시점의 `file_path` · `file_size` · `mime_type` 을 함께
 * 적어 두고, 후보 선정에서 **셋이 모두 지금 값과 같을 때만** 표식을 인정한다. 제자리 변환이나
 * 되돌리기로 셋 중 하나라도 달라지면 표식은 저절로 효력을 잃고 원본이 다시 후보가 된다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imgdel_variants', function (Blueprint $table) {
            $table->string('status', 16)->default('ready')->after('width')
                ->comment('ready = 실제 변환본 / skipped = 생성 불필요 표식');
            $table->string('skip_reason', 32)->nullable()->after('status')
                ->comment('표식 사유 (no_downscale_needed / source_pixel_cap 등)');
            $table->string('src_path', 255)->nullable()->after('src_height')
                ->comment('표식 시점 원본 file_path — 무효화 판정용');
            $table->unsignedBigInteger('src_bytes')->nullable()->after('src_path')
                ->comment('표식 시점 원본 file_size — 무효화 판정용');
            $table->string('src_mime', 100)->nullable()->after('src_bytes')
                ->comment('표식 시점 원본 mime_type — 무효화 판정용');

            $table->index(['upload_hash', 'status'], 'imgdel_variants_hash_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('imgdel_variants', function (Blueprint $table) {
            $table->dropIndex('imgdel_variants_hash_status_idx');
            $table->dropColumn(['status', 'skip_reason', 'src_path', 'src_bytes', 'src_mime']);
        });
    }
};
