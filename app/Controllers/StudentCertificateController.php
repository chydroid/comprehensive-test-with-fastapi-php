<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Certificate;
use Core\HttpException;

/**
 * 考生电子证书（C1）
 *
 *   GET /api/student/certificates          —— 我的证书（顺带把新达标的场次惰性签发）
 *   GET /api/student/certificates/{examId} —— 单张证书（用于「查看并打印」）
 *
 * 签发逻辑全部在 App\Services\Certificate，本控制器只做身份绑定与权限判定 ——
 * 考生永远只能拿到**自己**的证书，不存在按证书编号任意取证的入口
 * （公开核验走 /api/public/certificates/verify，且姓名已脱敏）。
 */
class StudentCertificateController extends BaseController
{
    /** GET /api/student/certificates */
    public function index(): \Core\Response
    {
        $sess = $this->authStudent();
        $list = Certificate::forStudent((string) $sess['id']);

        // 汇总卡片数据：避免前端为了「共计 N 张 / 最高分」而重算一遍
        $best = 0;
        foreach ($list as $c) {
            $best = max($best, (int) $c['score']);
        }

        return $this->ok([
            'list'  => $list,
            'stats' => [
                'total'    => count($list),
                'best'     => $best,
            ],
        ]);
    }

    /** GET /api/student/certificates/{examId} */
    public function show(): \Core\Response
    {
        $sess = $this->authStudent();
        $examId = $this->idParam('examId');
        $stuId = (string) $sess['id'];

        // 先取既有记录；没有再看能否惰性签发（考试刚结束、尚未打开过证书页的情况）
        $cert = Certificate::findOne($examId, $stuId) ?? Certificate::issue($examId, $stuId);
        if ($cert === null) {
            // 用 404 而非 403：不区分「考试不存在」「未达标」「尚未结束」，
            // 避免考生借错误码差异探测他人考试是否存在。
            throw new HttpException(404, '没有可用的证书：可能本场未设置证书、尚未结束、或成绩未达达标分', 40400);
        }

        $threshold = (int) $cert['threshold'];
        return $this->ok([
            'certificate' => $cert,
            'holder'      => (string) $cert['stu_name'],
            'verify_hint' => '证书编号可在他端「证书核验」处验证真伪：' . (string) $cert['cert_no'],
            'qualified'   => $threshold > 0 && (int) $cert['score'] >= $threshold,
        ]);
    }
}
