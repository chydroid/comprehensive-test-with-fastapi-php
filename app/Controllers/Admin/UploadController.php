<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use Core\HttpException;
use Core\Response;

/**
 * 图片上传（题目配图 / 头像）
 * 权限点：quiz.add（沿用中间件配置）
 *
 * 两种入参方式：
 *   1) multipart/form-data：字段名 file
 *   2) JSON：{filename:"a.png", data:"<base64>"}（兼容旧系统，绕开临时目录限制）
 *
 * 安全加固：
 * - 白名单扩展名 + 用 getimagesizefromstring 做真实图片校验（旧系统只看扩展名）
 * - 随机文件名，杜绝路径穿越与覆盖
 * - 大小上限来自 config('upload.max_size')
 */
class UploadController extends BaseController
{
    private const ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];

    /** POST /api/admin/upload/pic */
    public function picUpload(): Response
    {
        [$binary, $ext] = $this->readImage();

        // 真实图片校验（防伪造扩展名上传脚本）
        $info = @getimagesizefromstring($binary);
        if ($info === false) {
            throw new HttpException(400, '文件内容不是有效图片', 40002);
        }

        $maxSize = (int) config('upload.max_bytes', 2 * 1024 * 1024);
        if (strlen($binary) > $maxSize) {
            throw new HttpException(400, '图片大小超过限制（' . round($maxSize / 1048576, 1) . 'MB）', 40003);
        }

        $dir = (string) config('upload.path', BASE_PATH . '/public/uploads');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new HttpException(500, '上传目录不可写', 50000);
        }

        $filename = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $filename;

        if (@file_put_contents($dest, $binary) === false) {
            throw new HttpException(500, '图片保存失败，请检查目录权限', 50001);
        }

        $url = rtrim((string) config('upload.url', '/uploads'), '/') . '/' . $filename;

        return $this->ok([
            'filename' => $filename,
            'url'      => $url,
            'size'     => strlen($binary),
            'width'    => (int) $info[0],
            'height'   => (int) $info[1],
        ], '上传成功');
    }

    /* ------------------------------------------------------------------ */

    /**
     * 读取图片二进制与扩展名（multipart 或 base64 JSON）
     * @return array{0:string,1:string}
     */
    private function readImage(): array
    {
        // 1) multipart 上传
        $file = $this->request->file('file');
        if (is_array($file) && isset($file['tmp_name'])) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new HttpException(400, '文件上传失败（错误码 ' . (int) $file['error'] . '）', 40000);
            }
            $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
            $this->assertExt($ext);
            $binary = @file_get_contents((string) $file['tmp_name']);
            if ($binary === false || $binary === '') {
                throw new HttpException(400, '无法读取上传文件', 40001);
            }
            return [$binary, $ext === 'jpeg' ? 'jpg' : $ext];
        }

        // 2) base64 JSON
        $filename = (string) $this->request->input('filename', '');
        $data = (string) $this->request->input('data', '');
        if ($filename === '' || $data === '') {
            throw new HttpException(400, '请选择要上传的图片文件', 40000);
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $this->assertExt($ext);

        // 允许 data URI 前缀
        if (str_contains($data, ',')) {
            $data = substr($data, (int) strpos($data, ',') + 1);
        }
        $binary = base64_decode($data, true);
        if ($binary === false || $binary === '') {
            throw new HttpException(400, '图片数据解码失败', 40004);
        }

        return [$binary, $ext === 'jpeg' ? 'jpg' : $ext];
    }

    private function assertExt(string $ext): void
    {
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            throw new HttpException(
                400,
                '只允许上传 ' . implode(' / ', self::ALLOWED_EXT) . ' 格式的图片',
                40005
            );
        }
    }
}
