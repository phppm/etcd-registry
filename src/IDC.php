<?php

namespace ZanPHP\EtcdRegistry;


use ZanPHP\Config\Config;

class IDC
{
    private static $idc = null;

    const BC = "bc";
    const BD = "bd";

    public static function get()
    {
        if (self::$idc == NULL) {
            self::$idc = self::fromEnv();
        }
        return self::$idc;
    }

    private static function fromEnv()
    {
        $idc = Config::get("server.IDC", false);
        if ($idc !== false) {
            return $idc;
        }

        $opts = getopt("", [ "IDC::"]);
        if ($opts && isset($opts["IDC"]) && $idc = $opts["IDC"]) {
            return $idc;
        }

        $idc = getenv("kdt.IDC");
        if ($idc !== false) {
            return $idc;
        }

        $idc = get_cfg_var("kdt.IDC");
        if ($idc !== false) {
            return $idc;
        }

        return false;
    }
}