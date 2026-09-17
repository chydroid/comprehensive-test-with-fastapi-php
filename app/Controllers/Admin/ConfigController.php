<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\SiteConfig;
use App\Services\Setting;
use Core\HttpException;
use Core\Response;

/**
 * 站点配置（siteconfig 键值对）
 * 权限点：system.config（仅 systemAdmin）
 *
 * 两类配置共用本控制器：
 *   站点信息 —— 标题/版权/联系方式等展示内容，键白名单见 ALLOWED；
 *   系统参数 —— 入场窗口/限流/分页等运行时参数，由 App\Services\Setting 的 schema 驱动。
 * 二者都落在 siteconfig 表，但键名不重叠，读写接口分开以免误改。
 */
class ConfigController extends BaseController
{
    /** 允许前台展示的配置键及其长度上限 */
    private const ALLOWED = [
        'site_title' => 100,
        'copyright'  => 255,
        'address'    => 255,
        'phone'      => 50,
        'site_desc'  => 500,
        'icp'        => 100,
    ];

    private SiteConfig $model;

    public function __construct(\Core\Request $request, \Core\Response $response)
    {
        parent::__construct($request, $response);
        $this->model = new SiteConfig();
    }

    /** GET /api/admin/config */
    public function index(): Response
    {
        $map = $this->model->allAsMap();
        // 补齐未落库的键，前端无需做空值兜底
        foreach (self::ALLOWED as $key => $_) {
            if (!array_key_exists($key, $map)) {
                $map[$key] = '';
            }
        }
        return $this->ok($map);
    }

    /**
     * PUT /api/admin/config
     * body: {site_title, copyright, address, phone, ...}
     * 仅接受白名单键，避免写入任意配置污染系统。
     */
    public function update(): Response
    {
        $input = $this->request->all();
        $changed = [];

        foreach (self::ALLOWED as $key => $maxLen) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = trim((string) $input[$key]);
            if (mb_strlen($value) > $maxLen) {
                throw new HttpException(400, "配置项 {$key} 长度不能超过 {$maxLen} 字符", 40000);
            }
            $this->model->put($key, $value);
            $changed[$key] = $value;
        }

        if ($changed === []) {
            throw new HttpException(400, '没有可更新的配置项', 40001);
        }

        return $this->ok($this->model->allAsMap(), '配置已更新');
    }

    /* ==================================================================
     * 系统参数（运行时设置）
     * ================================================================== */

    /**
     * GET /api/admin/settings
     * 返回当前值 + 表单元数据（分组 / 类型 / 范围 / 帮助），前端据此渲染表单，
     * 新增设置项时后端改 schema 即可，前端无需同步改代码。
     */
    public function settings(): Response
    {
        $schema = Setting::adminSchema();
        return $this->ok([
            'values' => Setting::all(),
            // 哪些键在 siteconfig 中真的落过库。客户端（含测试脚本）据此做快照，
            // 临时改写后用 PUT {key: null} 精确还原，而不是凭有效值反推覆盖行。
            'stored' => Setting::storedKeys(),
            'groups' => $schema['groups'],
            'fields' => $schema['fields'],
        ]);
    }

    /**
     * PUT /api/admin/settings
     * body: {key: value, ...}，仅接受 schema 白名单键，逐项范围校验。
     */
    public function updateSettings(): Response
    {
        $input = $this->request->all();
        $saved = Setting::putMany($input);
        return $this->ok([
            'values' => Setting::all(),
            'saved'  => $saved,
        ], '设置已保存');
    }
}
