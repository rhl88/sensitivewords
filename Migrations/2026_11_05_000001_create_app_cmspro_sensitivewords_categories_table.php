<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 敏感词条分类表。
 *
 * 分类用于组织词条（涉政 / 涉黄 / 辱骂 / 广告等），并可为该分类下的词条
 * 指定默认处理动作；词条自身未指定动作时回落到分类默认动作。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_cmspro_sensitivewords_categories')) {
            return;
        }

        Schema::create('app_cmspro_sensitivewords_categories', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('主键ID');
            $table->string('name', 50)->comment('分类名称');
            $table->string('code', 50)->comment('分类标识（小写字母数字下划线，供代码引用）');
            $table->string('default_action', 20)->default('')->comment('分类默认动作：block=禁止 replace=替换 warn=提醒，空=跟随全局配置');
            $table->unsignedInteger('word_count')->default(0)->comment('词条数量（冗余计数，便于列表展示）');
            $table->unsignedTinyInteger('is_system')->default(0)->comment('是否系统内置：0=用户自建 1=系统内置（内置分类禁止删除）');
            $table->string('description', 255)->default('')->comment('分类说明');
            $table->integer('sort')->default(0)->comment('排序值，越小越靠前');
            $table->unsignedTinyInteger('status')->default(1)->comment('状态：0=禁用 1=启用');
            $table->timestamp('create_time')->nullable()->default(null)->comment('创建时间');
            $table->timestamp('update_time')->nullable()->default(null)->comment('更新时间');

            $table->unique('code');
            $table->index('status');
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE `app_cmspro_sensitivewords_categories` COMMENT '敏感词过滤-词条分类表'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_cmspro_sensitivewords_categories');
    }
};
