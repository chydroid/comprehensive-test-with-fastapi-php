<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Certificate;

/**
 * 证书公开核验（C1）
 *
 *   GET /api/public/certificates/verify?cert_no=xxxx
 *
 * 落在 `/api/public/` 前缀下，无需登录 —— 用人单位、考务方或任何拿到纸质/电子
 * 证书的一方，凭编号即可核实真伪，这正是「证书」区别于「成绩查询」的地方。
 *
 * 安全边界（重要）：
 *   - 证书编号含 32 位随机段，不可枚举；因此「知道编号」≈「持有证书」；
 *   - 返回的姓名一律脱敏（首尾保留、中间打星），不把持证人全名公开到互联网上 ——
 *     编号可能出现在公示、合影、二手教材等场合；
 *   - 不返回准考证号、身份证类信息，也不提供「按姓名查询」入口。
 */
class PublicCertificateController extends BaseController
{
    /** GET /api/public/certificates/verify */
    public function verify(): \Core\Response
    {
        $certNo = trim((string) $this->request->query('cert_no', ''));
        if ($certNo === '') {
            return $this->ok(['valid' => false, 'certificate' => null], '请输入证书编号');
        }
        return $this->ok(Certificate::verify($certNo));
    }
}
