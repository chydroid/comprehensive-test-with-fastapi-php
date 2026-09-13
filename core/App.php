<?php
declare(strict_types=1);

namespace Core;

use Throwable;

/**
 * 应用容器：加载配置、注册路由、管理中间件、统一错误处理
 */
class App
{
    private static ?App $instance = null;
    private array $config;
    private Router $router;
    /** @var Middleware[] */
    private array $middlewares = [];

    public function __construct(array $config)
    {
        self::$instance = $this;
        $GLOBALS['__config'] = $config;
        $this->config = $config;
        $this->router = new Router();
        $this->registerRoutes();
        $this->registerErrorHandlers();
    }

    public static function instance(): App
    {
        if (self::$instance === null) {
            throw new \LogicException('App 尚未初始化');
        }
        return self::$instance;
    }

    public function config(): array
    {
        return $this->config;
    }

    /** 访问路由器（可用于运行时注册路由，例如带路由级中间件的动态路由） */
    public function router(): Router
    {
        return $this->router;
    }

    /** 追加中间件（按注册顺序执行） */
    public function middleware(Middleware $middleware): static
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /** 处理请求并输出响应，返回最终 Response 便于测试/调用方检查 */
    public function run(): Response
    {
        $request = new Request();
        $response = new Response();

        // 请求 ID：优先透传客户端 X-Request-ID，否则生成，用于日志关联与 500 排查
        $GLOBALS['__request_id'] = $request->header('X-Request-ID') ?: bin2hex(random_bytes(8));
        $GLOBALS['__request'] = $request;
        $start = microtime(true);

        // 畸形 JSON 请求体：返回清晰 400，避免误导性字段校验错误
        if (($bodyError = $request->bodyError()) !== null) {
            $response->error($bodyError->errorCode, $bodyError->getMessage(), $bodyError->statusCode);
            return $this->finish($request, $response, microtime(true) - $start);
        }

        // 请求体大小限制（防超大请求拖垮服务），CLI 测试无 CONTENT_LENGTH 时不受影响
        $maxBody = (int) ($this->config['app']['max_body_bytes'] ?? 1048576);
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBody) {
            $response->error(41300, '请求体过大', 413);
            return $this->finish($request, $response, microtime(true) - $start);
        }

        try {
            // 中间件链包裹路由分发：中间件先于路由匹配执行
            $response = $this->handle($request, $response);
        } catch (HttpException $e) {
            $response->error($e->errorCode, $e->getMessage(), $e->statusCode, $e->errorData);
            if ($e->allowedMethods !== []) {
                $response->header('Allow', implode(', ', $e->allowedMethods));
            }
        } catch (\PDOException $e) {
            // 唯一约束/外键等完整性冲突（SQLSTATE 23000）→ 409，而非 500
            $this->logError($e);
            if ($e->getCode() === '23000') {
                $message = $this->isDebug() ? $e->getMessage() : '数据重复或违反唯一约束';
                $response->error(40900, $message, 409, ['request_id' => $GLOBALS['__request_id']]);
            } else {
                $message = $this->isDebug() ? $e->getMessage() : '服务器内部错误';
                $response->error(50000, $message, 500, ['request_id' => $GLOBALS['__request_id']]);
            }
        } catch (Throwable $e) {
            $this->logError($e);
            $message = $this->isDebug() ? $e->getMessage() : '服务器内部错误';
            $response->error(50000, $message, 500, ['request_id' => $GLOBALS['__request_id']]);
        }

        return $this->finish($request, $response, microtime(true) - $start);
    }

    /** 收尾：附加请求 ID 响应头、指标采集、访问日志、慢请求告警并输出 */
    private function finish(Request $request, Response $response, float $elapsed): Response
    {
        $response->header('X-Request-ID', $GLOBALS['__request_id']);
        // HEAD 请求按 HTTP 规范只返回头，不输出 body
        if ($request->method() === 'HEAD') {
            $response->skipBody();
        }
        // 指标采集：用路由模式（/api/users/{id}）避免实际路径导致高基数；
        // 未匹配（404/405 等）路径统一归入 <unknown>，防止任意路径制造无界序列
        $pattern = $this->router->match($request->method(), $request->path()) ?? '<unknown>';
        Metrics::recordRequest($request->method(), $pattern, $response->statusCode(), $elapsed);
        $this->logAccess($request, $response->statusCode(), $elapsed);
        $this->logSlow($request, $elapsed);
        $response->send();
        return $response;
    }

    /* --------------------------------------------------------------------- */

    private function registerRoutes(): void
    {
        $routes = require BASE_PATH . '/config/routes.php';
        if (!is_array($routes)) {
            throw new \LogicException('routes.php 必须返回数组');
        }
        foreach ($routes as $route) {
            if (count($route) < 3 || count($route) > 4) {
                throw new \LogicException('路由定义必须为 [method, path, handler] 或 [method, path, handler, middlewares]');
            }
            [$method, $path, $handler] = $route;
            $this->router->add($method, $path, $handler, $route[3] ?? []);
        }
    }

    private function registerErrorHandlers(): void
    {
        date_default_timezone_set((string) config('app.timezone', 'UTC'));
        set_exception_handler(fn (Throwable $e) => $this->handleUncaught($e));
        set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
            // 尊重 @ 抑制：error_reporting() 为 0 时跳过，
            // 避免 @mkdir/@file_put_contents 等失败被误转为异常导致 500
            if (!(error_reporting() & $no)) {
                return false;
            }
            // 弃用告警（PHP 8.x 大量存在，如隐式 null 传参）不应导致 500，交由默认处理记录
            if ($no === E_DEPRECATED || $no === E_USER_DEPRECATED) {
                return false;
            }
            throw new \ErrorException($str, 0, $no, $file, $line);
        });
    }

    /** 洋葱模型：构建中间件链，最内层为路由分发 + 处理器 + 路由级中间件 */
    private function handle(Request $request, Response $response): Response
    {
        $chain = function (Request $req) use ($response): Response {
            [$handler, $params, $routeMiddleware] = $this->router->dispatch($req);
            $req->setParams($params);
            // 路由级中间件包裹在处理器外层（顺序即定义顺序）
            $inner = fn (): Response => $this->callHandler($req, $response, $handler);
            foreach (array_reverse($routeMiddleware) as $mw) {
                $inner = fn () => $this->resolveMiddleware($mw)->handle($req, $inner);
            }
            return $inner();
        };
        foreach (array_reverse($this->middlewares) as $middleware) {
            $chain = fn (Request $req) => $middleware->handle($req, $chain);
        }
        return $chain($request);
    }

    /** 解析路由级中间件：支持类名或实例 */
    private function resolveMiddleware(string|Middleware $middleware): Middleware
    {
        return is_string($middleware) ? new $middleware() : $middleware;
    }

    /** 调用路由处理器：闭包直接调用，数组为 [控制器, 方法] */
    private function callHandler(Request $request, Response $response, callable|array $handler): Response
    {
        // 用 is_array 显式区分，不依赖 is_callable 对 [类, 非静态方法] 的版本相关行为
        $result = is_array($handler)
            ? $this->callController($request, $response, $handler)
            : $handler($request, $response);

        return $result instanceof Response ? $result : $response->success($result);
    }

    private function callController(Request $request, Response $response, array $handler): mixed
    {
        [$class, $method] = $handler;
        $controller = new $class($request, $response);
        return $controller->$method(...array_values($request->params()));
    }

    /** 未捕获异常（run 之外，如 shutdown 阶段） */
    private function handleUncaught(Throwable $e): void
    {
        $response = new Response();
        if ($e instanceof HttpException) {
            $response->error($e->errorCode, $e->getMessage(), $e->statusCode, $e->errorData);
        } else {
            $this->logError($e);
            $response->error(50000, $this->isDebug() ? $e->getMessage() : '服务器内部错误', 500);
        }
        $response->header('X-Request-ID', $GLOBALS['__request_id'] ?? '');
        $response->send();
        // CLI 下以非 0 退出，便于测试运行器识别"未捕获异常"导致的失败
        if (PHP_SAPI === 'cli') {
            exit(1);
        }
    }

    private function logError(Throwable $e): void
    {
        $ctx = '';
        $req = $GLOBALS['__request'] ?? null;
        if ($req instanceof Request) {
            $ctx = sprintf(' | %s %s from %s', $req->method(), $req->path(), $req->ip());
        }
        log_message(
            $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . $ctx,
            'error'
        );
    }

    /** 访问日志：方法、路径、状态码、耗时（请求 ID 由 log_message 统一附加） */
    private function logAccess(Request $request, int $status, float $elapsed): void
    {
        log_message(sprintf(
            '%s %s -> %d (%.1fms)',
            $request->method(),
            $request->path(),
            $status,
            $elapsed * 1000
        ), 'info');
    }

    /** 慢请求告警：超过 log.slow_ms 记 warning */
    private function logSlow(Request $request, float $elapsed): void
    {
        $ms = $elapsed * 1000;
        if ($ms >= (float) ($this->config['log']['slow_ms'] ?? 1000)) {
            log_message(sprintf(
                'SLOW %s %s %.1fms',
                $request->method(),
                $request->path(),
                $ms
            ), 'warning');
        }
    }

    private function isDebug(): bool
    {
        return (bool) ($this->config['app']['debug'] ?? false);
    }
}
