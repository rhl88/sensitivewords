<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 敏感词条表。
 *
 * 词条是过滤引擎的数据源：word 为敏感词本体，word_lower 为归一化（小写 +
 * 全角转半角）后的匹配键，引擎按 word_lower 建 DFA 字典树。source 标记词条
 * 来源，在线词库覆盖更新时只处理 source=library 的词条，不影响用户自建词条。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_cmspro_sensitivewords_words')) {
            return;
        }

        Schema::create('app_cmspro_sensitivewords_words', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键ID');
            $table->string('word', 191)->comment('敏感词原文');
            $table->string('word_lower', 191)->comment('归一化匹配键（小写+全角转半角），引擎按此建词典树');
            $table->unsignedBigInteger('category_id')->default(0)->comment('分类ID，关联 app_cmspro_sensitivewords_categories 表，0=未分类');
            $table->string('action', 20)->default('')->comment('处理动作：block=禁止 replace=替换 warn=提醒，空=跟随分类/全局配置');
            $table->unsignedTinyInteger('level')->default(1)->comment('敏感级别：1=低 2=中 3=高（供调用方按级别差异化处理）');
            $table->string('source', 20)->default('manual')->comment('来源：manual=手工添加 import=文件导入 library=在线词库');
            $table->unsignedBigInteger('hit_count')->default(0)->comment('累计命中次数');
            $table->timestamp('last_hit_time')->nullable()->default(null)->comment('最近一次命中时间');
            $table->string('remark', 255)->default('')->comment('备注');
            $table->unsignedTinyInteger('status')->default(1)->comment('状态：0=禁用 1=启用');
            $table->timestamp('create_time')->nullable()->default(null)->comment('创建时间');
            $table->timestamp('update_time')->nullable()->default(null)->comment('更新时间');

            $table->unique('word_lower');
            $table->index('category_id');
            $table->index(['status', 'source']);
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE `app_cmspro_sensitivewords_words` COMMENT '敏感词过滤-词条表'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_cmspro_sensitivewords_words');
    }
};
