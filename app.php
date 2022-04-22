<?php

class G
{
    public static function get()
    {
        return App_Context::get('get');
    }

    public static function post()
    {
        return App_Context::get('post');
    }

    public static function pathInfo()
    {
        return App_Context::get('pathInfo');
    }

    public static function config($key = '')
    {
        return App_Context::getConfig($key);
    }

    public static function arrayGet($arr, $keys, $default = null)
    {
        if (!is_array($arr)) {
            $arr = [];
        }

        if (!is_string($keys)) {
            $keys = '';
        }

        $keyArr = explode('.', $keys);
        $re     = $arr;
        foreach ($keyArr as $k) {
            if (!isset($re[$k])) {
                $re = $default;
                break;
            } else {
                $re = $re[$k];
            }
        }

        return $re;
    }

    public static function xssClean($data)
    {
        if (!is_string($data)) {
            return false;
        }

        // Fix &entity\n;
        $data = str_replace(array('&amp;', '&lt;', '&gt;'), array('&amp;amp;', '&amp;lt;', '&amp;gt;'), $data);
        $data = preg_replace('/(&#*\w+)[\x00-\x20]+;/u', '$1;', $data);
        $data = preg_replace('/(&#x*[0-9A-F]+);*/iu', '$1;', $data);
        $data = html_entity_decode($data, ENT_COMPAT, 'UTF-8');

        // Remove any attribute starting with "on" or xmlns
        $data = preg_replace('#(<[^>]+?[\x00-\x20"\'])(?:on|xmlns)[^>]*+>#iu', '$1>', $data);

        // Remove javascript: and vbscript: protocols
        $data = preg_replace('#([a-z]*)[\x00-\x20]*=[\x00-\x20]*([`\'"]*)[\x00-\x20]*j[\x00-\x20]*a[\x00-\x20]*v[\x00-\x20]*a[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iu', '$1=$2nojavascript...', $data);
        $data = preg_replace('#([a-z]*)[\x00-\x20]*=([\'"]*)[\x00-\x20]*v[\x00-\x20]*b[\x00-\x20]*s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:#iu', '$1=$2novbscript...', $data);
        $data = preg_replace('#([a-z]*)[\x00-\x20]*=([\'"]*)[\x00-\x20]*-moz-binding[\x00-\x20]*:#u', '$1=$2nomozbinding...', $data);

        // Only works in IE: <span style="width: expression(alert('Ping!'));"></span>
        $data = preg_replace('#(<[^>]+?)style[\x00-\x20]*=[\x00-\x20]*[`\'"]*.*?expression[\x00-\x20]*\([^>]*+>#i', '$1>', $data);
        $data = preg_replace('#(<[^>]+?)style[\x00-\x20]*=[\x00-\x20]*[`\'"]*.*?behaviour[\x00-\x20]*\([^>]*+>#i', '$1>', $data);
        $data = preg_replace('#(<[^>]+?)style[\x00-\x20]*=[\x00-\x20]*[`\'"]*.*?s[\x00-\x20]*c[\x00-\x20]*r[\x00-\x20]*i[\x00-\x20]*p[\x00-\x20]*t[\x00-\x20]*:*[^>]*+>#iu', '$1>', $data);

        // Remove namespaced elements (we do not need them)
        $data = preg_replace('#</*\w+:\w[^>]*+>#i', '', $data);

        do {
            // Remove really unwanted tags
            $old_data = $data;
            $data     = preg_replace('#</*(?:applet|b(?:ase|gsound|link)|embed|frame(?:set)?|i(?:frame|layer)|l(?:ayer|ink)|meta|object|s(?:cript|tyle)|title|xml)[^>]*+>#i', '', $data);
        } while ($old_data !== $data);

        // we are done...
        return $data;
    }

    public static function strBeginsWith($str, $sub)
    {
        return substr($str, 0, strlen($sub)) === $sub;
    }

    public static function strEndWith($str, $sub)
    {
        return substr($str, -strlen($sub)) === $sub;
    }

    public static function autoloadCls($clsName)
    {
        $workDir = str_replace('\\', '/', APP_PATH);
        if (G::strEndWith($workDir, 'public/')) {
            $workDir = substr($workDir, 0, -strlen('public/')) . 'app/';
        }

        $clsName = str_replace('\\', '/', $clsName);
        if (G::strBeginsWith($clsName, 'controllers/')) {
            include $workDir . $clsName . '.php';
            return true;
        }

        return false;
    }
}

class App
{
    public function __construct($config = [])
    {
        date_default_timezone_set(G::arrayGet($config, 'timezoneIdentifier', 'PRC'));
        $this->setContext();
        $this->setConfig($config);
        spl_autoload_register(['G', 'autoloadCls']);
    }

    public function setContext()
    {
        $context['get']     = isset($_GET) ? $_GET : [];
        $context['post']    = isset($_POST) ? $_POST : [];
        $context['server']  = isset($_SERVER) ? $_SERVER : [];
        $context['files']   = isset($_FILES) ? $_FILES : [];
        $context['cookie']  = isset($_COOKIE) ? $_COOKIE : [];
        $context['session'] = isset($_SESSION) ? $_SESSION : [];
        (function () use (&$context) {
            if (!$pathInfo = G::arrayGet($context['server'], 'PATH_INFO', G::arrayGet($context['server'], 'REDIRECT_PATH_INFO', ''))) {
                if ($reqUri = G::arrayGet($context['server'], 'REQUEST_URI', '')) {
                    if (G::strBeginsWith($reqUri, '//')) {
                        $reqUri = ltrim($reqUri, '/');
                    }
                    $pathInfo = parse_url($reqUri, PHP_URL_PATH);
                }
            }
            $context['pathInfo'] = $pathInfo;
        })();
        App_Context::set($context);
    }

    public function setConfig($config)
    {
        App_Context::setConfig($config);
    }

    public function uri2ClsName()
    {
        if (!G::strEndWith(G::pathInfo(), G::config('url.suffix'))) {
            return false;
        }
        $uriArr                     = explode('/', rtrim(G::pathInfo(), G::config('url.suffix')));
        $uriArr[0]                  = 'controllers';
        $funcName                   = array_pop($uriArr);
        $uriArr[count($uriArr) - 1] = ucfirst($uriArr[count($uriArr) - 1]);
        $cls                        = '\\' . implode('\\', $uriArr);
        $obj                        = new $cls();
        $method                     = 'action' . ucfirst($funcName);
        if (!method_exists($obj, $method)) {
            return false;
        }
        call_user_func_array([$obj, 'action' . ucfirst($funcName)], []);
    }

    public function run()
    {
        $this->uri2ClsName();
    }


}

class App_Context
{
    private static $context = [];
    private static $config = [];

    public static function get($type)
    {
        return G::arrayGet(self::$context, $type);
    }

    public static function set($context)
    {
        self::$context = $context;
    }

    public static function setConfig($config)
    {
        self::$config = $config;
    }

    public static function getConfig($key)
    {
        if (!$key) {
            return self::$config;
        }
        return G::arrayGet(self::$config, $key);
    }
}

abstract class App_Controller
{
}
