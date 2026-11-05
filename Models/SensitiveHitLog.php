<?php

namespace App\Apps\CmsproSensitivewords\Models;

use App\Models\BaseModel;

/**
 * 敏感词命中日志模型。
 *
 * 每次命中写一条：来源应用、场景、命中词、最终动作、原文快照、请求上下文。
 * 日志仅用于审计与词库调优，不参与过滤决策，因此写入失败不得影响主流程。
 */
class SensitiveHitLog extends BaseModel
{
    protected $table = 'app_cmspro_sensitivewords_hit_logs';

    public const CREATED_AT = 'create_time';

    public const UPDATED_AT = 'update_time';

    protected $fillable = [
        'source_app',
        'scene',
        'words',
        'word_count',
        'action',
        'content',
        'request_path',
        'ip',
        'user_id',
        'admin_id',
        'create_time',
    ];

    protected $casts = [
        'word_count' => 'integer',
        'user_id' => 'integer',
        'admin_id' => 'integer',
        'create_time' => 'datetime:Y-m-d H:i:s',
        'update_time' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * 命中词清单（字符串转数组）。
     *
     * @return array<int,string>
     */
    public function wordList(): array
    {
        $words = trim((string) $this->words);

        return $words === '' ? [] : array_values(array_filter(explode(',', $words), fn ($w) => $w !== ''));
    }
}
