<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\Material;
use Core\HttpException;
use Core\Response;

/**
 * 管理端学习资料库（C3，轻量版）。
 *
 * 权限点：material.view / material.add / material.delete
 *
 * 上传与「新增条目」刻意分成两步：先 POST /upload 拿到 url，再 POST /materials
 * 存元数据。合成一步会让「只登记一个外链、不上传文件」这种常见做法无从表达，
 * 也让上传失败时连表单一起丢。
 */
class MaterialController extends BaseController
{
    /** GET /api/admin/materials */
    public function index(): Response
    {
        $this->authAdmin();
        $page = max(1, (int) $this->request->query('page', 1));
        $perPage = min(100, max(5, (int) $this->request->query('per_page', 20)));

        $result = Material::list(
            [
                'subj_id'  => (int) $this->request->query('subj_id', 0),
                'category' => (string) $this->request->query('category', ''),
                'keyword'  => (string) $this->request->query('keyword', ''),
            ],
            ($page - 1) * $perPage,
            $perPage
        );

        return $this->ok([
            'data'       => $result['data'],
            'total'      => $result['total'],
            'page'       => $page,
            'per_page'   => $perPage,
            'categories' => Material::categories(),
        ]);
    }

    /** POST /api/admin/materials */
    public function save(): Response
    {
        $sess = $this->authAdmin();
        $in = $this->validate([
            'title'     => 'required|maxlen:200',
            'subj_id'   => 'integer',
            'category'  => 'maxlen:60',
            'summary'   => 'maxlen:500',
            'file_name' => 'maxlen:255',
            'file_url'  => 'required|maxlen:255',
            'file_ext'  => 'maxlen:16',
            'file_size' => 'integer',
        ]);

        // file_url 只允许站内 /uploads 路径或 http(s) 外链：
        // 否则可以存一个 javascript: 或 data: 链接，考生点开就在站点上下文里执行。
        $url = trim((string) $in['file_url']);
        if (!$this->isSafeUrl($url)) {
            throw new HttpException(400, '文件地址无效，仅支持站内上传路径或 http(s) 链接', 40000);
        }

        $row = Material::create($in + ['uploader' => (string) ($sess['username'] ?? 'admin')]);

        $this->audit('material.add', 'material:' . ($row['id'] ?? 0), [
            'title' => $row['title'] ?? '',
        ]);

        return $this->ok($row, '资料已添加');
    }

    /** DELETE /api/admin/materials/{id} */
    public function delete(): Response
    {
        $this->authAdmin();
        $id = $this->idParam();
        Material::delete($id);
        $this->audit('material.delete', 'material:' . $id, []);
        return $this->ok(['id' => $id], '资料已删除');
    }

    /**
     * POST /api/admin/materials/upload —— 课件文档上传
     *
     * 与图片上传（UploadController）分开，是因为两者的校验完全不同：
     * 图片可以用 getimagesize 验真，文档只能靠扩展名白名单。混在一起会让
     * 「图片必须验真」这条约束被文档的宽松校验稀释掉。
     */
    public function upload(): Response
    {
        $this->authAdmin();

        $file = $this->request->file('file');
        if (!is_array($file) || !isset($file['tmp_name'])) {
            throw new HttpException(400, '请选择要上传的文件', 40000);
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException(400, '文件上传失败（错误码 ' . (int) $file['error'] . '）', 40001);
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = (array) config('upload.allowed_doc_ext', []);
        if (!in_array($ext, $allowed, true)) {
            throw new HttpException(
                400,
                '不支持的文件类型（. ' . $ext . '），仅支持：' . implode(' / ', $allowed),
                40002
            );
        }

        $size = (int) ($file['size'] ?? 0);
        $max = (int) config('upload.doc_max_bytes', 20971520);
        if ($size > $max) {
            throw new HttpException(400, '文件超过 ' . round($max / 1048576, 1) . 'MB 上限', 40003);
        }

        $binary = @file_get_contents((string) $file['tmp_name']);
        if ($binary === false || $binary === '') {
            throw new HttpException(400, '无法读取上传文件', 40004);
        }

        $dir = (string) config('upload.path', BASE_PATH . '/public/uploads');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new HttpException(500, '上传目录不可写', 50000);
        }

        // 随机文件名：原始名只进 file_name 字段用于展示，绝不参与落盘路径
        $filename = 'doc_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $filename;
        if (@file_put_contents($dest, $binary) === false) {
            throw new HttpException(500, '文件保存失败，请检查目录权限', 50001);
        }

        return $this->ok([
            'filename'  => $filename,
            'url'       => rtrim((string) config('upload.url', '/uploads'), '/') . '/' . $filename,
            'orig_name' => (string) ($file['name'] ?? ''),
            'size'      => strlen($binary),
            'ext'       => $ext,
        ], '上传成功');
    }

    private function isSafeUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        if (str_starts_with($url, '/uploads/')) {
            return true;
        }
        return (bool) preg_match('#^https?://#i', $url);
    }
}
