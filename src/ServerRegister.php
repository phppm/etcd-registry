<?php

namespace ZanPHP\EtcdRegistry;


use ZanPHP\Contracts\Config\Repository;
use ZanPHP\Coroutine\Task;
use ZanPHP\EtcdRegistry\Exception\ServerConfigException;
use ZanPHP\EtcdRegistry\Utils\Curl;
use ZanPHP\HttpClient\HttpClient;
use ZanPHP\HttpClient\Response;
use ZanPHP\Support\Time;
use ZanPHP\Timer\Timer;

class ServerRegister
{
    const DEFAULT_ETD_TTL = 30;
    const DEFAULT_REGISTRY_TYPE = "etcd";

    private $enableRefresh = true;

    public static function createEtcdV2KV($config, $status = ServerDiscovery::SRV_STATUS_OK)
    {
        // key := "/" + server.Protocol + ":" + server.Namespace + "/" + server.SrvName + "/" + server.IP + ":" + strconv.Itoa(server.Port)
        $protocol = $config["protocol"];
        $namespace = $config["domain"];
        $srvName = $config["appName"];
        $ip = nova_get_ip();
        $port = make(Repository::class)->get('server.port');

        $extData = [];
        foreach ($config['services'] as $service) {
            $extData[] = [
                'language'=> 'php',
                'version' => '1.0.0',
                'timestamp'=> Time::stamp(),
                'service' => $service['service'],
                'methods' => $service['methods'],
            ];
        }

        $etcdV2Key = "$protocol:$namespace/$srvName/$ip:$port";

        $etcdV2Value = [
            'DataCenter' => IDC::get() ?: IDC::BC,
            'Namespace' => $namespace,
            'SrvName' => $srvName,
            'IP' => $ip,
            'Port' => (int)$port,
            'Protocol' => $protocol,
            'Status' => $status,
            'Weight' => 100,
            'ExtData' => json_encode($extData),
        ];

        return [$etcdV2Key, $etcdV2Value];
    }

    public static function getRandEtcdNode()
    {
        $nodes = make(Repository::class)->get("registry.etcd.nodes", []);
        if (empty($nodes)) {
            throw new ServerConfigException("empty etcd nodes in registry.etcd.nodes");
        }
        return $nodes[array_rand($nodes)];
    }

    public static function createHauntBody($config, $status = ServerDiscovery::SRV_STATUS_OK)
    {
        $protocol = $config["protocol"];
        $namespace = $config["domain"];
        $srvName = $config["appName"];
        $ip = nova_get_ip();
        $port = make(Repository::class)->get('server.port');

        $extData = [];
        foreach ($config['services'] as $service) {
            $extData[] = [
                'language'=> 'php',
                'version' => '1.0.0',
                'timestamp'=> Time::stamp(),
                'service' => $service['service'],
                'methods' => $service['methods'],
            ];
        }

        return [
            'SrvList' => [
                [
                    // Haunt 不需要加上机房信息
                    'Namespace' => $namespace,
                    'SrvName' => $srvName,
                    'IP' => $ip,
                    'Port' => (int)$port,
                    'Protocol' => $protocol,
                    'Status' => $status,
                    'Weight' => 100,
                    'ExtData' => json_encode($extData),
                ]
            ]
        ];
    }

    public function register($config)
    {
        $type = make(Repository::class)->get("registry.type", self::DEFAULT_REGISTRY_TYPE);
        while (true) {
            $ret = false;
            if ($type === "etcd") {
                /** @noinspection PhpVoidFunctionResultUsedInspection */
                $ret = $this->masterRegisterToEtcdV2($config);
            } else if($type === "haunt") {
                /** @noinspection PhpVoidFunctionResultUsedInspection */
                $ret = $this->masterRegisterByHaunt($config);
            }
            if ($ret === true) {
                break;
            }
            sleep(1);
        }
    }

    private function masterRegisterToEtcdV2($config) {
        $node = static::getRandEtcdNode();
        list($etcdV2Key, $etcdV2Value) = static::createEtcdV2KV($config);
        $detail = $this->inspect($etcdV2Value);

        sys_echo("registering [$detail]");
        $params = [
            "value" => json_encode($etcdV2Value),
            "ttl" => static::DEFAULT_ETD_TTL,
        ];

        $url = "http://{$node["host"]}:{$node["port"]}/v2/keys/$etcdV2Key";
        $curl = new Curl();
        $curl->setHeader([
            'Content-Type' => 'application/x-www-form-urlencoded'
        ]);

        $resp = $curl->request(Curl::METHOD_PUT, $url, $params);

        if ($resp->isError()) {
            sys_echo("register to etcd:$url failed, caused by ".$resp->getError());
            return false;
        }

        $statusCode = $resp->statusCode();
        $body = $resp->response();

        if ($statusCode >= 200 && $statusCode < 300) {
            sys_echo("Register to etcd success [code=$statusCode]");
            return true;
        } else {
            sys_error("status=$statusCode, body=$body");
            return false;
        }
    }

    private function registerToEtcdV2($config, $isRefresh = false)
    {
        $node = static::getRandEtcdNode();

        list($etcdV2Key, $etcdV2Value) = static::createEtcdV2KV($config);
        $detail = $this->inspect($etcdV2Value);

        if ($isRefresh) {
            sys_echo("refreshing [$detail]");
            $params = [
                "ttl" => static::DEFAULT_ETD_TTL,
                "refresh" => true,
                "prevExist" => true,
            ];
        } else {
            sys_echo("registering [$detail]");
            $params = [
                "value" => json_encode($etcdV2Value),
                "ttl" => static::DEFAULT_ETD_TTL,
            ];
        }

        // TODO: NOTICE
        // 这里由于 master进程不能设置超时, 如果当前etcd节点挂了, 不会有回调, 协程会死在这里
        $httpClient = new HttpClient($node["host"], $node["port"]);
        $httpClient->setMethod("PUT");
        // 这里只有 worker 进程的refresh, 可以成功设置超时
        if (isset($_SERVER["WORKER_ID"])) {
            $httpClient->setTimeout(3000);
        }
        $httpClient->setUri("/v2/keys/$etcdV2Key");
        $httpClient->setBody(http_build_query($params));
        $httpClient->setHeader([
            'Content-Type' => 'application/x-www-form-urlencoded'
        ]);

        $statusCode = 0;

        try {
            /** @var Response $response */
            $response = (yield $httpClient->build());
            $statusCode = $response->getStatusCode();
            $body = $response->getBody();
            if ($statusCode >= 200 && $statusCode < 300) {
                if ($isRefresh === false) {
                    sys_echo("Register to etcd success [code=$statusCode]");
                }
                // WARNING	php_swoole_add_timer: cannot use timer in master process.
                // $this->refreshingTTL($config);
                $this->enableRefresh = true;
                return;
            } else {
                sys_error("status=$statusCode, body=$body");
            }
        }
        catch (\Throwable $e) { }
        catch (\Exception $e) { }
        if (isset($e)) {
            if ($isRefresh) {
                sys_error("service refresh fail [$detail]");
            } else {
                sys_error("service register fail [$detail]");
            }
            echo_exception($e);
        }

        $this->enableRefresh = false;

        if ($statusCode === 404) {
            // 如果 404 key not found, 服务下线, 停止刷新
            return;
        } else {
            // refresh 失败 可能是ttl过期, 这里直接set而不是刷新
            Timer::after(1000, function () use ($config) {
                /** @noinspection PhpVoidFunctionResultUsedInspection */
                $co = $this->registerToEtcdV2($config);
                Task::execute($co);
            });
        }
    }

    public function refreshingEtcdV2TTL($config)
    {
        $type = make(Repository::class)->get("registry.type", self::DEFAULT_REGISTRY_TYPE);
        if ($type === "etcd") {
            // !!! 刷新时间必须少于ttl的一半，应对worker重启的极端情况
            $interval = (static::DEFAULT_ETD_TTL / 2 - 5) * 1000;
            Timer::tick(intval($interval), function() use($config) {
                if ($this->enableRefresh) {
                    /** @noinspection PhpVoidFunctionResultUsedInspection */
                    $task = $this->registerToEtcdV2($config, true);
                    Task::execute($task);
                }
            });
        }
    }

    private function masterRegisterByHaunt($config)
    {
        $haunt = make(Repository::class)->get('registry.haunt');

        $body = static::createHauntBody($config);
        $detail = $this->inspect($body['SrvList'][0]);
        sys_echo("registering [$detail]");

        $url = "http://{$haunt["host"]}:{$haunt["port"]}/{$haunt["register"]["uri"]}";
        $curl = new Curl();
        $curl->setHeader([
            'Content-Type' => 'application/x-www-form-urlencoded'
        ]);

        $resp = $curl->request(Curl::METHOD_POST, $url, $body);

        if ($resp->isError()) {
            sys_echo("register to haunt:$url failed, caused by ".$resp->getError());
            return false;
        }

        $body = $resp->response();
        $msg = rtrim($body, "\n");
        sys_echo("$msg [$detail]");
        return true;
    }

    private function inspect($config)
    {
        $map = [];
        foreach ($config as $k => $v) {
            if ($k === "ExtData") {
                continue;
            }
            $map[] = "$k=$v";
        }
        return implode(", ", $map);
    }
}