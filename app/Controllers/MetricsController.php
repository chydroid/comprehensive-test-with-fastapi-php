<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Controller;
use Core\Metrics;
use Core\Response;

/**
 * Prometheus 指标输出
 * GET /api/metrics —— text/plain（非 envelope 包装）
 */
class MetricsController extends Controller
{
    public function metrics(): Response
    {
        return $this->response->text(Metrics::render());
    }
}
