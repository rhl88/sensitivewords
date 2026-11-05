<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 敏感词命中日志表。
 *
 * 记录每一次命中：来源应用（source_app）、业务场景（scene）、命中词清单、
 * 最终动作与原文快照，用于事后审计与词库调优。原文快照按配置截断存储，
 * 避免长文本占满磁盘；content 字段仅存片段，不存完整业务数据。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_cmspro_sensitivewords_hit_logs')) {
            return;
        }

        Schema::create('app_cmspro_sensitivewords_hit_logs', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键ID');
            $table->string('source_app', 64)->default('')->comment('来源应用ID（如 cmspro.blog），空=系统或未声明');
            $table->string('scene', 64)->default('')->comment('业务场景标识（由调用方传入，如 comment.create）');
            $table->string('words', 500)->default('')->comment('命中的敏感词，多个用逗号分隔');
            $table->unsignedTinyInteger('word_count')->default(0)->comment('命中词条数');
            $table->string('action', 20)->default('')->comment('最终执行动作：block=禁止 replace=替换 warn=提醒');
            $table->text('content')->nullable()->comment('原文快照（按配置截断，可能为空）');
            $table->string('request_path', 255)->default('')->comment('触发请求路径');
            $table->string('ip', 45)->default('')->comment('来源IP');
            $table->unsignedBigInteger('user_id')->default(0)->comment('触发用户ID（前台用户，0=未登录）');
            $table->unsignedBigInteger('admin_id')->default(0)->comment('触发管理员ID（后台操作，0=非后台）');
            $table->timestamp('create_time')->nullable()->default(null)->comment('创建时间');
            $table->timestamp('update_time')->nullable()->default(null)->comment('更新时间');

            $table->index(['source_app', 'scene']);
            $table->index('create_time');
            $table->index('action');
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE `app_cmspro_sensitivewords_hit_logs` COMMENT '敏感词过滤-命中日志表'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_cmspro_sensitivewords_hit_logs');
    }
};
