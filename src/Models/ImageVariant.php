<?php

namespace Plugins\G7\Image\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Plugins\G7\Image\Delivery\Support\VariantPath;

/**
 * 변환본 1건.
 *
 * @property int $id
 * @property string $upload_hash
 * @property int $width
 * @property string $format
 * @property string $token
 * @property string $path
 * @property int $byte_size
 * @property int $src_width
 * @property int $src_height
 * @property int $out_width
 * @property int $out_height
 */
class ImageVariant extends Model
{
    protected $table = 'imgdel_variants';

    protected $fillable = [
        'upload_hash', 'width', 'format', 'token', 'path',
        'byte_size', 'src_width', 'src_height', 'out_width', 'out_height', 'generated_at',
    ];

    protected $casts = [
        'width' => 'integer',
        'byte_size' => 'integer',
        'src_width' => 'integer',
        'src_height' => 'integer',
        'out_width' => 'integer',
        'out_height' => 'integer',
        'generated_at' => 'datetime',
    ];

    /**
     * 이 변환본의 공개 URL.
     */
    public function publicUrl(): string
    {
        return VariantPath::publicUrl($this->upload_hash, $this->width, $this->token, $this->format);
    }
}
