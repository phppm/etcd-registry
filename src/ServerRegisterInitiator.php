<?php

namespace ZanPHP\EtcdRegistry;

use Kdt\Iron\Nova\Nova;
use ZanPHP\Contracts\Config\Repository;
use ZanPHP\Coroutine\Task;
use ZanPHP\Support\Singleton;

class ServerRegisterInitiator
{
    use Singleton;

    CONST ENABLE_REGISTER = 1;
    CONST DISABLE_REGISTER = 0;

    private $register;

    public function __construct()
    {
        $this->register =  make(Repository::class)->get('registry.enable', self::ENABLE_REGISTER);
    }

    public function enableRegister()
    {
        $this->register = self::ENABLE_REGISTER;
    }

    public function disableRegister()
    {
        $this->register = self::DISABLE_REGISTER;
    }

    public function getRegister()
    {
        return $this->register;
    }

    public function init()
    {
        if (!$this->register) {
            return;
        }

        $configs = $this->createRegisterConfigs();
        foreach ($configs as $config) {
            $register = new ServerRegister();
            $register->register($config);
        }
    }

    public function createRegisterConfigs()
    {
        $configs = [];
        $keys = Nova::getEtcdKeyList();
        foreach ($keys as list($protocol, $domain, $appName)) {
            $config = [];
            $config["services"] = Nova::getAvailableService($protocol, $domain, $appName);
            $config["domain"] = $domain;
            $config["appName"] = $appName;
            $config["protocol"] = $protocol;
            $configs[] = $config;
        }
        return $configs;
    }
}