<?php

namespace App\Apps\CmsproSensitivewords\Tests\Feature;

use App\Apps\CmsproSensitivewords\Models\SensitiveWord;
use App\Apps\CmsproSensitivewords\Services\SensitiveLibraryService;
use App\Apps\CmsproSensitivewords\Tests\SensitivewordsTestCase;
use Illuminate\Support\Facades\Http;

/**
 * 在线词库远程检查与同步测试（回归防护：数组 + 运算符丢弃键的缺陷）。
 *
 * 事故背景：checkRemoteLibrary() 用 `return $base + ['available'=>true, ...]`
 * 返回成功结果，但 PHP 数组 `+` 运算符是「左键优先」——$base 已定义全部 15 个键，
 * 右侧补丁的同名键（available/message/version/checksum 等）被**全部丢弃**。
 * 于是无论远程版本服务多正常，该方法的成功路径永远返回 available=false，
 * syncRemoteLibrary() 随即抛「版本服务不可用」，接口返回 50002。
 *
 * 关键取证：远程 HTTP 200 + code:0 + 192 条词库，但 checkRemoteLibrary 返回
 * available=false、message=''（恰为 $base 原始值），且日志无 catch 分支 warning，
 * 证明未走异常路径而是成功结果被运算符吞掉。修复须改用 array_merge。
 */
class RemoteLibrarySyncTest extends SensitivewordsTestCase
{
    private function library(): SensitiveLibraryService
    {
        return app(SensitiveLibraryService::class);
    }

    /**
     * 构造远程 latest 接口的标准成功响应。
     */
    private function fakeLatestSuccess(string $version = '20261106001', int $wordCount = 192, string $checksum = 'abc123'): void
    {
        Http::fake([
            '*sensitive-words/latest' => Http::response([
                'code' => 0,
                'message' => 'success',
                'data' => [
                    'version' => $version,
                    'word_count' => $wordCount,
                    'file_size' => 2000,
                    'checksum' => $checksum,
                    'release_time' => '2026-11-06 13:14:46',
                    'changelog' => '',
                ],
            ]),
        ]);
    }

    // ══ 一、核心回归：成功路径不得被数组 + 吞掉 ══════════════════════

    public function test_check_remote_library_returns_available_on_success(): void
    {
        $this->fakeLatestSuccess();

        $info = $this->library()->checkRemoteLibrary();

        // 根因断言：修复前此处为 false（$base 的 available 未被覆盖）
        $this->assertTrue($info['available'], 'checkRemoteLibrary 成功路径应返回 available=true，实际被数组 + 运算符吞掉');
        $this->assertSame('20261106001', $info['version']);
        $this->assertSame(192, $info['word_count']);
        $this->assertSame('abc123', $info['checksum']);
        $this->assertSame('2026-11-06 13:14:46', $info['release_time']);
        // 本地版本为空，远程有版本 → 需要更新
        $this->assertTrue($info['need_update']);
    }

    public function test_check_remote_library_success_keeps_local_fields(): void
    {
        // 成功路径除覆盖远程字段外，仍须保留 local_version / local_count。
        // 直接用模型建 library 来源词条：createWord() 会把 source 硬编码为 manual，
        // 无法用于构造本地在线词库计数
        $this->seedLibraryWord('赌博');
        $this->seedLibraryWord('色情');
        $this->fakeLatestSuccess('v9', 2, 'x');

        $info = $this->library()->checkRemoteLibrary();

        $this->assertTrue($info['available']);
        $this->assertSame('v9', $info['version']);
        $this->assertSame(2, $info['local_count']);
        // 本地无版本记录 → need_update 为真
        $this->assertTrue($info['need_update']);
    }

    public function test_no_need_update_when_versions_match(): void
    {
        // 本地版本与远程一致时 need_update 应为 false
        $this->seedLibraryVersion('20261106001');
        $this->fakeLatestSuccess('20261106001');

        $info = $this->library()->checkRemoteLibrary();

        $this->assertTrue($info['available']);
        $this->assertFalse($info['need_update']);
    }

    // ══ 二、失败路径 message 必须保留（同样受数组 + 影响）══════════════

    public function test_check_remote_library_reports_http_error(): void
    {
        Http::fake(['*sensitive-words/latest' => Http::response('', 500)]);

        $info = $this->library()->checkRemoteLibrary();

        $this->assertFalse($info['available']);
        $this->assertStringContainsString('版本服务返回异常', $info['message']);
        $this->assertStringContainsString('500', $info['message']);
    }

    public function test_check_remote_library_reports_no_published_library(): void
    {
        // 远程返回 code!=0 或 data 为空
        Http::fake(['*sensitive-words/latest' => Http::response(['code' => 1, 'message' => '暂无已发布的官方词库', 'data' => null])]);

        $info = $this->library()->checkRemoteLibrary();

        $this->assertFalse($info['available']);
        $this->assertStringContainsString('暂无已发布的官方词库', $info['message']);
    }

    public function test_check_remote_library_reports_connection_failure(): void
    {
        Http::fake(['*sensitive-words/latest' => Http::response('', 500)]);
        // 用超时/连接异常模拟无法连接
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });

        $info = $this->library()->checkRemoteLibrary();

        $this->assertFalse($info['available']);
        $this->assertStringContainsString('无法连接版本服务', $info['message']);
    }

    public function test_sync_remote_throws_specific_reason_not_generic(): void
    {
        // 修复前：syncRemoteLibrary 因 available=false 抛兜底「版本服务不可用」，
        // 掩盖真实原因；修复后 HTTP 500 应透传为「版本服务返回异常（HTTP 500）」
        Http::fake(['*sensitive-words/latest' => Http::response('', 500)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('版本服务返回异常');

        $this->library()->syncRemoteLibrary();
    }

    // ══ 三、端到端同步：成功路径必须真正下载并入库 ══════════════════

    public function test_sync_remote_library_end_to_end(): void
    {
        // 用应用自身的发布包构造器生成真实 zip（含 words.txt + meta.json）
        $this->seedWord('赌博', ['action' => 'block', 'level' => 3]);
        $this->seedWord('色情', ['action' => 'block', 'level' => 3]);
        $package = $this->library()->buildReleasePackage([], '20261106001');

        Http::fake([
            '*sensitive-words/latest' => Http::response([
                'code' => 0,
                'data' => [
                    'version' => '20261106001',
                    'word_count' => $package['word_count'],
                    'checksum' => $package['checksum'],
                    'release_time' => '2026-11-06 13:14:46',
                ],
            ]),
            '*sensitive-words/download/*' => Http::response($package['content'], 200, ['Content-Type' => 'application/zip']),
        ]);

        // 清空模拟全新站点，同步后应落库为 library 来源
        SensitiveWord::query()->delete();

        $result = $this->library()->syncRemoteLibrary();

        $this->assertSame('20261106001', $result['version']);
        $this->assertGreaterThan(0, $result['inserted']);
        $this->assertNotNull(SensitiveWord::where('source', SensitiveWord::SOURCE_LIBRARY)->first());
        // 同步后本地版本号被记录
        $this->assertSame('20261106001', $this->library()->localLibraryVersion());
    }

    public function test_sync_remote_verifies_checksum(): void
    {
        $this->seedWord('赌博', ['action' => 'block']);
        $package = $this->library()->buildReleasePackage([], '20261106001');

        Http::fake([
            '*sensitive-words/latest' => Http::response([
                'code' => 0,
                'data' => ['version' => '20261106001', 'word_count' => $package['word_count'], 'checksum' => 'wrong_checksum_on_purpose'],
            ]),
            '*sensitive-words/download/*' => Http::response($package['content'], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('校验失败');

        $this->library()->syncRemoteLibrary();
    }

    /**
     * 建一条 library（在线词库）来源的词条。
     *
     * 不用 seedWord/createWord：后者把 source 硬编码为 manual，
     * 无法用于构造「本地已有在线词库词条」的计数场景。
     */
    private function seedLibraryWord(string $word): SensitiveWord
    {
        return SensitiveWord::create([
            'word' => $word,
            'word_lower' => app(\App\Apps\CmsproSensitivewords\Services\SensitiveWordManageService::class)->buildMatchKey($word),
            'category_id' => 0,
            'action' => 'block',
            'level' => 3,
            'source' => SensitiveWord::SOURCE_LIBRARY,
            'status' => 1,
        ]);
    }

    /**
     * 写入本地已同步版本号（模拟此前成功同步过）。
     */
    private function seedLibraryVersion(string $version): void
    {
        $group = \App\Models\ConfigGroup::where('code', 'app_cmspro_sensitivewords_library_settings')->first();
        $this->assertNotNull($group, 'library_settings 配置组应由测试基类创建');

        \App\Models\ConfigItem::updateOrCreate(
            ['code' => 'app_cmspro_sensitivewords_library_version'],
            ['group_id' => $group->id, 'name' => '本地词库版本', 'type' => 'text', 'value' => $version, 'sort' => 0, 'status' => 1]
        );
        cmspro_sensitivewords_forget_config_cache(['library_version']);
    }
}
