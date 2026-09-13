<?php

declare(strict_types=1);

namespace Core;

/**
 * Swoole 常驻内存适配层
 *
 * 让本框架无需改动业务代码即可运行在 Swoole 常驻进程中，获得每请求省去进程启动开销的性能收益。
 *
 * 原理：
 *  - dispatch()：把 Swoole 请求数据映射到 PHP 超全局 + 注入 raw body，跑一遍 App，
 *    再用 Response 捕获模式收集 status/headers/body。该纯逻辑可脱离 Swoole 独立测试。
 *  - start()：启动 Swoole\Http\Server，把每个请求交给 dispatch()，把结果写回 Swoole 响应。
 *    （需安装 swoole 扩展）
 *
 * 常驻收益：Router 编译缓存、DB/Redis/缓存连接、App 单例在常驻进程中复用。
 *
 * ⚠️ 已知限制（协程）：本适配假定**单 worker 内串行处理请求**（阻塞式 DB 调用，不启用
 * PDO/MySQL 的协程钩子）。若用 Swoole 协程钩子（SWOOLE_HOOK_ALL 等）包裹阻塞 DB 调用，
 * 则同一 worker 内并发请求会共享 core\Database 的静态连接，导致结果串扰/事务错乱。
 * 生产如需协程化，请为连接池做协程上下文（按 cid 隔离），属进阶改造。
 */
final class SwooleAdapter
{
    /**
     * 处理单个请求并返回 [status, headers, body]（捕获模式，不真正输出）
     * @param array<string,mixed> $server 模拟 $_SERVER
     */
    public static function dispatch(array $server, array $get = [], array $post = [], array $files = [], ?string $rawBody = null): array
    {
        // 重置请求级全局（常驻进程里这些是跨请求残留的）
        unset($GLOBALS['__request'], $GLOBALS['__request_id'], $GLOBALS['__jwt']);

        $_SERVER = $server;
        $_GET    = $get;
        $_POST   = $post;
        $_FILES  = $files;
        Request::setInputBody($rawBody);
        Response::capture(true);

        try {
            // 常驻：已 bootstrap 则复用 App（Router/连接/缓存）；否则首次引导
            try {
                $app = App::instance();
            } catch (\LogicException) {
                $app = bootstrap();
            }
            $app->run();
        } finally {
            Request::clearInputBody();
            Response::capture(false); // 不重置已收集的数据
            Database::release();      // 归还连接池（池未启用时为空操作）
        }

        return Response::captured();
    }

    /** 启动 Swoole HTTP 服务器（需 swoole 扩展） */
    public static function start(array $config = []): void
    {
        if (!extension_loaded('swoole')) {
            throw new \RuntimeException('需要安装 swoole 扩展（pecl install swoole）才能启动常驻服务器');
        }
        $host = (string) ($config['host'] ?? '0.0.0.0');
        $port = (int) ($config['port'] ?? 9501);

        $server = new \Swoole\Http\Server($host, $port);
        $server->set([
            'worker_num'            => (int) ($config['workers'] ?? 4),
            'daemonize'             => false,
            'enable_static_handler' => false,
            'max_request'           => 0, // 常驻不按请求数回收，最大化复用
        ]);

        $server->on('request', static function ($request, $response): void {
            $res = self::dispatch(
                self::serverVars($request),
                $request->get ?? [],
                $request->post ?? [],
                $request->files ?? [],
                $request->rawContent() ?: null
            );
            $response->status($res['status']);
            foreach ($res['headers'] as $name => $value) {
                $response->header($name, $value);
            }
            $response->end($res['body']);
        });

        $server->start();
    }

    /** 把 Swoole 请求映射为 $_SERVER（含 HTTP_* 头） */
    private static function serverVars($request): array
    {
        $s = $request->server ?? [];
        $h = $request->header ?? [];
        $vars = [
            'REQUEST_METHOD' => strtoupper((string) ($s['request_method'] ?? 'GET')),
            'REQUEST_URI'    => (string) ($s['request_uri'] ?? '/'),
            'REMOTE_ADDR'    => (string) ($s['remote_addr'] ?? '0.0.0.0'),
        ];
        if (!empty($s['content_type'])) {
            $vars['CONTENT_TYPE'] = (string) $s['content_type'];
        }
        foreach ($h as $name => $value) {
            if ($name === 'content-type') {
                continue; // 由 CONTENT_TYPE 处理
            }
            $vars['HTTP_' . strtoupper(str_replace('-', '_', $name))] = (string) $value;
        }
        return $vars;
    }
}
