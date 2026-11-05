<?php

namespace App\Apps\CmsproSensitivewords\Models;

use App\Enums\Status;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 敏感词条模型。
 *
 * 词条是过滤引擎的唯一数据源。word_lower 为归一化匹配键（小写 + 全角转半角），
 * 引擎按它建 DFA 字典树并加唯一索引，保证同一归一化键不会重复入库。
 * source 区分手工/导入/在线词库三种来源，在线词库覆盖更新只处理 source=library。
 */
class SensitiveWord extends BaseModel
{
    protected $table = 'app_cmspro_sensitivewords_words';

    public const CREATED_AT = 'create_time';

    public const UPDATED_AT = 'update_time';

    /** 来源：内置基础词库（随应用分发，安装时导入） */
    public const SOURCE_BUILTIN = 'builtin';

    /** 来源：手工添加 */
    public const SOURCE_MANUAL = 'manual';

    /** 来源：文件导入 */
    public const SOURCE_IMPORT = 'import';

    /** 来源：在线词库（版本服务分发） */
    public const SOURCE_LIBRARY = 'library';

    /** 动作：禁止（拦截并返回错误） */
    public const ACTION_BLOCK = 'block';

    /** 动作：替换（掩码字符替换命中词） */
    public const ACTION_REPLACE = 'replace';

    /** 动作：提醒（放行原文但记录日志） */
    public const ACTION_WARN = 'warn';

    protected $fillable = [
        'word',
        'word_lower',
        'category_id',
        'action',
        'level',
        'source',
        'hit_count',
        'last_hit_time',
        'remark',
        'status',
    ];

    protected $casts = [
        'status' => Status::class,
        'category_id' => 'integer',
        'level' => 'integer',
        'hit_count' => 'integer',
        'last_hit_time' => 'datetime:Y-m-d H:i:s',
        'create_time' => 'datetime:Y-m-d H:i:s',
        'update_time' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * 所属分类。
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(SensitiveCategory::class, 'category_id', 'id');
    }

    /**
     * 启用中的词条。
     */
    public function scopeEnabled($query)
    {
        return $query->where('status', Status::ENABLED);
    }

    /**
     * 按指定来源筛选（在线词库覆盖更新用）。
     */
    public function scopeOfSource($query, string $source)
    {
        return $query->where('source', $source);
    }
}
