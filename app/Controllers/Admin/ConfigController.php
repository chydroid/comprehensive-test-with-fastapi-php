<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\SiteConfig;
use Core\HttpException;
use Core\Response;

/**
 * 站点配置 —— siteconfig（键值对）
 * 权限点：system.config（仅 systemAdmin）
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
}
