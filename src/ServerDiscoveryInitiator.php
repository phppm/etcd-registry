<?php

namespace ZanPHP\EtcdRegistry;

use Kdt\Iron\Nova\Foundation\TService;
use Kdt\Iron\Nova\Nova;
use ZanPHP\Contracts\Config\Repository;
use ZanPHP\EtcdRegistry\Exception\ServerConfigException;
use ZanPHP\NovaConnectionPool\NovaClientConnectionManager;
use ZanPHP\ServiceStore\ServiceStore;
use ZanPHP\Support\Singleton;
use ZanPHP\Support\Time;

class ServerDiscoveryInitiator
{
    use Singleton;

    public function init($workerId)
    {
        $repository = make(Repository::class);
        $config = $repository->get('registry');
        if (empty($config)) {
            throw new ServerConfigException("registry config is not found, see: http://zanphpdoc.zanphp.io/config/registry.html");
        }
        $config = $this->noNeedDiscovery($config);
        if (!isset($config['app_names']) || [] === $config['app_names']) {
            return;
        }

        // 为特定app指定protocol 与 domain
        $appConfigs = $repository->get('registry.app_configs', []);
        foreach ($config['app_names'] as $appName) {
            if (!isset($appConfigs[$appName]) || !is_array($appConfigs[$appName])) {
                $appConfigs[$appName] = [];
            }
            $appConfigs[$appName] += [
                "protocol" => ServerDiscovery::DEFAULT_PROTOCOL,
                "namespace" => ServerDiscovery::DEFAULT_NAMESPACE
            ];
        }

        if ($workerId === 0) {
        // if (ServerStore::getInstance()->lockDiscovery($workerId)) {
            sys_echo("worker *$workerId service discovery from etcd");
            foreach ($config['app_names'] as $appName) {
                $appConf = $appConfigs[$appName];
                $serverDiscovery = new ServerDiscovery($config, $appName, $appConf["protocol"], $appConf["namespace"]);
                $serverDiscovery->workByEtcd();
            }
        } else {
            sys_echo("worker *$workerId service discovery from apcu");
            foreach ($config['app_names'] as $appName) {
                $appConf = $appConfigs[$appName];
                $serverDiscovery = new ServerDiscovery($config, $appName, $appConf["protocol"], $appConf["namespace"]);
                $serverDiscovery->workByStore();
            }
        }
    }

    public function unlockDiscovery($workerId)
    {
        // return ServerStore::getInstance()->unlockDiscovery($workerId);
    }

    public function noNeedDiscovery($config)
    {
        $repository = make(Repository::class);
        $noNeedDiscovery = $repository->get('service_discovery');
        if (empty($noNeedDiscovery)) {
            return $config;
        }
        if (!isset($noNeedDiscovery['app_names']) || !is_array($noNeedDiscovery['app_names']) || [] === $noNeedDiscovery['app_names']) {
            return $config;
        }
        if (isset($config['app_names']) && is_array($config['app_names']) && [] !== $config['app_names']) {
            foreach ($config['app_names'] as $key => $appName) {
                if (in_array($appName, $noNeedDiscovery['app_names'])) {
                    unset($config['app_names'][$key]);
                }
            }
        }
        foreach ($noNeedDiscovery['app_names'] as $appName) {
            if (!isset($noNeedDiscovery['novaApi'][$appName])) {
                continue;
            }
            if (!isset($noNeedDiscovery['connection'][$appName])) {
                continue;
            }
            $novaConfig = $noNeedDiscovery['novaApi'][$appName];
            $novaConfig += [ "domain" => ServerDiscovery::DEFAULT_NAMESPACE ];


            $services = [];
            $path = getenv("path.root") . $novaConfig["path"] . "/";
            $baseNamespace = $novaConfig["namespace"];
            $specMap = Nova::getSpec($path, $baseNamespace);
            $ts = Time::stamp();
            foreach ($specMap as $className => $spec) {
                $services[] = [
                    "language"=> "php",
                    "version"=> "1.0.0",
                    "timestamp"=> $ts,
                    "service" => TService::getNovaServiceName($spec->getServiceName()),
                    "methods"=> $spec->getServiceMethods(),
                ];
            }

            //reset $servers
            $servers = [];
            $servers[$noNeedDiscovery['connection'][$appName]['host'].':'.$noNeedDiscovery['connection'][$appName]['port']] = [
                'idc' => null,
                'app_name' => $appName,
                'host' => $noNeedDiscovery['connection'][$appName]['host'],
                'port' => $noNeedDiscovery['connection'][$appName]['port'],
                'services' => $services,
                'namespace' => $novaConfig['domain'],
                'protocol' => "nova",
                'status' => 1,
                'weight' => 100,
            ];

            ServiceStore::getInstance()->setServices($appName, $servers);
            /* @var $connMgr NovaClientConnectionManager */
            $connMgr = NovaClientConnectionManager::getInstance();
            $connMgr->work($appName, $servers);
        }

        return $config;
    }
}