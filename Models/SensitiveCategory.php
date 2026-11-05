<?php

namespace App\Apps\CmsproSensitivewords\Models;

use App\Enums\Status;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 敏感词条分类模型。
 *
 * 分类承载「该类目词条的默认处理动作」：词条未指定 action 时回落到分类的
 * default_action，分类也为空时再回落到全局配置 default_action。
 */
class SensitiveCategory extends BaseModel
{
    protected $table = 'app_cmspro_sensitivewords_categories';

    public const CREATED_AT = 'create_time';

    public const UPDATED_AT = 'update_time';

    protected $fillable = [
        'name',
        'code',
        'default_action',
        'word_count',
        'is_system',
        'description',
        'sort',
        'status',
    ];

    protected $casts = [
        'status' => Status::class,
        'word_count' => 'integer',
        'is_system' => 'integer',
        'sort' => 'integer',
        'create_time' => 'datetime:Y-m-d H:i:s',
        'update_time' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * 分类下的词条。
     */
    public function words(): HasMany
    {
        return $this->hasMany(SensitiveWord::class, 'category_id', 'id');
    }

    /**
     * 启用中的分类。
     */
    public function scopeEnabled($query)
    {
        return $query->where('status', Status::ENABLED);
    }

    /**
     * 按 code 取启用中的分类（供在线词库归类与代码引用）。
     */
    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }
}
