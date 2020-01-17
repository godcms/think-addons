<?php
// +-----------------------------------------------------------------------------------------------
// | 插件公共函数
// +-----------------------------------------------------------------------------------------------

use think\App;
use think\Cache;
use think\Config;
use think\Exception;
use think\Hook;
use think\Loader;
use think\Route;

// 插件目录
define('ADDON_PATH', ROOT_PATH . 'addons' . DS);

// 如果插件目录不存在则创建
if (!is_dir(ADDON_PATH)) {
    @mkdir(ADDON_PATH, 0755, true);
}

// 所有请求都支持的路由规则
// \Think\Route::any('路由规则','路由地址','路由参数','变量规则');
Route::any('addons/:addon/[:controller]/[:action]', "\\think\\addons\\Route@execute");

// 注册类的根命名空间
Loader::addNamespace('addons', ADDON_PATH);

// 监听addon_init
Hook::listen('addon_init');

// 闭包自动识别插件目录配置
Hook::add('app_init', function () {
    // 获取开关
    $autoload = (bool) Config::get('addons.autoload', false);
    // 非正是返回
    if (!$autoload) {
        return;
    }
    // 当debug时不缓存配置
    $config = App::$debug ? [] : Cache::get('addons', []);
    if (empty($config)) {
        $config = get_addon_autoload_config(); //获得插件自动加载的配置
        Cache::set('addons', $config);
    }
});

// 闭包初始化行为
Hook::add('app_init', function () {

    //注册路由
    $routeArr = (array) Config::get('addons.route');
    $domains = [];
    $rules = [];
    $execute = "\\think\\addons\\Route@execute?addon=%s&controller=%s&action=%s";
    foreach ($routeArr as $k => $v) {
        if (is_array($v)) {
            $addon = $v['addon'];
            $domain = $v['domain'];
            $drules = [];
            foreach ($v['rule'] as $m => $n) {
                list($addon, $controller, $action) = explode('/', $n);
                $drules[$m] = sprintf($execute . '&indomain=1', $addon, $controller, $action);
            }
            $domains[$domain] = $drules ? $drules : [];
            $domains[$domain][':controller/[:action]'] = sprintf($execute . '&indomain=1', $addon, ":controller", ":action");
        } else {
            if (!$v) {continue;}
            list($addon, $controller, $action) = explode('/', $v);
            $rules[$k] = sprintf($execute, $addon, $controller, $action);
        }
    }
    Route::rule($rules);
    if ($domains) {
        Route::domain($domains);
    }

    // 获取插件钩子
    $hooks = App::$debug ? [] : Cache::get('hooks', []);
    if (empty($hooks)) {
        $hooks = (array) Config::get('addons.hooks');
        foreach ($hooks as $key => $values) {
            if (is_string($values)) {
                $values = explode(',', $values);
            } else {
                $values = (array) $values;
            }
            $hooks[$key] = array_filter(array_map('get_addon_class', $values));
        }
        Cache::set('hooks', $hooks);
    }

    //如果在插件中有定义 app_init ，则直接执行
    if (isset($hooks['app_init'])) {
        foreach ($hooks['app_init'] as $k => $v) {
            Hook::exec($v, 'app_init');
        }
    }

    //批量导入插件钩子
    Hook::import($hooks, false);
});

/**
 * 处理插件钩子
 * @param string $hook 钩子名称
 * @param mixed $params 传入参数
 * @return void
 */

function hook($hook, $params = [])
{
    Hook::listen($hook, $params);
}

/**
 * 获得插件列表
 * @return array
 */
function get_addon_list()
{
    $results = scandir(ADDON_PATH);
    $list = [];
    foreach ($results as $name) {
        if ($name === '.' or $name === '..') {
            continue;
        }

        if (is_file(ADDON_PATH . $name)) {
            continue;
        }

        $addonDir = ADDON_PATH . $name . DS;
        if (!is_dir($addonDir)) {
            continue;
        }

        if (!is_file($addonDir . ucfirst($name) . '.php')) {
            continue;
        }

        //这里不采用get_addon_info是因为会有缓存
        //$info = get_addon_info($name);
        $info_file = $addonDir . 'info.ini';
        if (!is_file($info_file)) {
            continue;
        }

        //解析配置文件或内容
        $info = Config::parse($info_file, '', "addon-info-{$name}");
        //插件显示内容里生成访问插件的url
        $info['route'] = addon_url($name);
        $list[$name] = $info;

    }
    return $list;
}

/**
 * 获得插件自动加载的配置
 * @return array
 */
function get_addon_autoload_config($truncate = false)
{
    // 读取addons的配置
    $config = (array) Config::get('addons');
    if ($truncate) {
        $config['hooks'] = []; // 清空手动配置的钩子
    }

    $route = [];

    // 读取插件目录及钩子列表
    $base = get_class_methods("\\think\\Addons");
    $base = array_merge($base, ['install', 'uninstall', 'enable', 'disable']);
    $url_domain_deploy = Config::get('url_domain_deploy'); // 域名部署
    $addons = get_addon_list();
    $domain = [];
    foreach ($addons as $name => $addon) {
        if (!$addon['state']) {
            continue; //插件禁用，无需设置。
        }

        // 读取出所有公共方法
        $methods = (array) get_class_methods("\\addons\\" . $name . "\\" . ucfirst($name));
        // 跟插件基类方法做比对，得到差异结果
        $hooks = array_diff($methods, $base);
        // 循环将钩子方法写入配置中
        foreach ($hooks as $hook) {
            $hook = Loader::parseName($hook, 0, false);
            if (!isset($config['hooks'][$hook])) {
                $config['hooks'][$hook] = [];
            }
            // 兼容手动配置项
            if (is_string($config['hooks'][$hook])) {
                $config['hooks'][$hook] = explode(',', $config['hooks'][$hook]);
            }
            if (!in_array($name, $config['hooks'][$hook])) {
                $config['hooks'][$hook][] = $name;
            }
        }

        // 插件路由设置
        $conf = get_addon_config($addon['name']);
        if ($conf) {
            $conf['rewrite'] = isset($conf['rewrite']) && is_array($conf['rewrite']) ? $conf['rewrite'] : [];
            $rule = array_map(function ($value) use ($addon) {
                return "{$addon['name']}/{$value}";
            }, array_flip($conf['rewrite']));
            if ($url_domain_deploy && isset($conf['domain']) && $conf['domain']) {
                $domain[] = [
                    'addon' => $addon['name'],
                    'domain' => $conf['domain'],
                    'rule' => $rule,
                ];
            } else {
                $route = array_merge($route, $rule);
            }
        }

    }
    $config['route'] = $route;
    $config['route'] = array_merge($config['route'], $domain);
    return $config;
}

/**
 * 获取插件的单例
 * @param $name
 * @return mixed|null
 */
function get_addon_instance($name)
{
    static $_addons = [];
    if (isset($_addons[$name])) {
        return $_addons[$name];
    }
    $class = get_addon_class($name);
    if (class_exists($class)) {
        $_addons[$name] = new $class();
        return $_addons[$name];
    } else {
        return null;
    }
}

/**
 * 获取插件类的类名
 * @param $name 插件名
 * @param string $type 返回命名空间类型
 * @param string $class 当前类名
 * @return string
 */
function get_addon_class($name, $type = 'hook', $class = null)
{
    // 字符串命名风格转换
    $name = Loader::parseName($name);

    // 处理多级控制器情况
    if (!is_null($class) && strpos($class, '.')) {
        $class = explode('.', $class);

        $class[count($class) - 1] = Loader::parseName(end($class), 1);
        $class = implode('\\', $class);
    } else {
        $class = Loader::parseName(is_null($class) ? $name : $class, 1);
    }

    //
    switch ($type) {
        case 'controller':
            $namespace = "\\addons\\" . $name . "\\controller\\" . $class;
            break;
        default:
            $namespace = "\\addons\\" . $name . "\\" . $class;
    }

    return class_exists($namespace) ? $namespace : '';
}

/**
 * 读取插件的基础信息
 * @param string $name 插件名
 * @return array
 */
function get_addon_info($name)
{
    $addon = get_addon_instance($name);
    if (!$addon) {
        return [];
    }
    return $addon->getInfo($name);
}

/**
 * 获取插件的配置数组(key => value)
 * @param string $name 插件名
 * @return array
 */
function get_addon_config($name)
{
    $addon = get_addon_instance($name);
    if (!$addon) {
        return [];
    }
    return $addon->getConfig($name);
}

/**
 * 获取插件类的配置数组
 * @param string $name 插件名
 * @return array
 */
function get_addon_full_config($name)
{
    $addon = get_addon_instance($name);
    if (!$addon) {
        return [];
    }
    return $addon->getFullConfig($name);
}

/**
 * 插件显示内容里生成访问插件的地址
 * @param $url 地址 格式：插件名/控制器/方法
 * @param array $vars 变量参数
 * @param bool|string $suffix 生成的URL后缀
 * @param bool|string $domain 域名
 * @return bool|string
 * addon_url('article/channel/index', [':id' => $data['id'], ':rewrite' => $rewrite]);
 * addon_url('article/channel/index', [':id' => $data['id'], ':rewrite' => $rewrite],true,true);
 */
function addon_url($url, $vars = [], $suffix = true, $domain = false)
{
    $url = ltrim($url, '/');

    // 获取插件名（插件名/控制器/方法）
    if (stripos($url, '/')) {
        $addon = substr($url, 0, stripos($url, '/'));
    } else {
        $addon = $url;
    }

    // 将 query string 解析成 array
    if (!is_array($vars)) {
        parse_str($vars, $params);
        $vars = $params;
    }

    // 带：的变量赋值给$params, 同时把$vars里带:的变量删掉
    $params = [];
    foreach ($vars as $k => $v) {
        if (substr($k, 0, 1) === ':') {
            $params[$k] = $v;
            unset($vars[$k]);
        }
    }

    //
    $val = "@addons/{$url}";

    // 获取插件的配置数组
    $config = get_addon_config($addon);

    // 获取当前请求的调度信息 Array ( [type] => module [module] => Array ( [0] => 模块 [1] => 控制器 [2] => 方法 ) )
    $dispatch = think\Request::instance()->dispatch();
    // 是否采用域名部署
    $indomain = isset($dispatch['var']['indomain']) && $dispatch['var']['indomain'] ? true : false;
    $domainprefix = $config && isset($config['domain']) && $config['domain'] ? $config['domain'] : '';
    // 是否重写路由
    $rewrite = $config && isset($config['rewrite']) && $config['rewrite'] ? $config['rewrite'] : [];
    if ($rewrite) {
        $path = substr($url, stripos($url, '/') + 1);
        if (isset($rewrite[$path]) && $rewrite[$path]) {
            $val = $rewrite[$path];
            array_walk($params, function ($value, $key) use (&$val) {
                $val = str_replace("[{$key}]", $value, $val);
            });
            $val = str_replace(['^', '$'], '', $val);
            if (substr($val, -1) === '/') {
                $suffix = false;
            }
        } else {
            // 如果采用了域名部署,则需要去掉前两段
            if ($indomain && $domainprefix) {
                $arr = explode("/", $val);
                $val = implode("/", array_slice($arr, 2));
            }
            foreach ($params as $k => $v) {
                $vars[substr($k, 1)] = $v;
            }
        }
    } else {
        // 如果采用了域名部署,则需要去掉前两段
        if ($indomain && $domainprefix) {
            $arr = explode("/", $val);
            $val = implode("/", array_slice($arr, 2));
        }
        foreach ($params as $k => $v) {
            $vars[substr($k, 1)] = $v;
        }
    }

    return url($val, [], $suffix, $domain) . ($vars ? '?' . http_build_query($vars) : '');
}

/**
 * 设置基础配置信息
 * @param string $name 插件名
 * @param array $array
 * @return boolean
 * @throws Exception
 */
function set_addon_info($name, $array)
{
    $addon = get_addon_instance($name);
    if ($addon) {
        return $addon->setInfo($name, $array);
    }
    return false;
}

/**
 * 写入配置文件
 * @param string $name 插件名
 * @param array $config 配置数据
 * @param boolean $writefile 是否写入配置文件
 */
function set_addon_config($name, $config, $writefile = true)
{
    $addon = get_addon_instance($name);
    $addon->setConfig($name, $config);
    $fullconfig = get_addon_full_config($name);
    foreach ($fullconfig as $k => &$v) {
        if (isset($config[$v['name']])) {
            $value = $v['type'] !== 'array' && is_array($config[$v['name']]) ? implode(',', $config[$v['name']]) : $config[$v['name']];
            $v['value'] = $value;
        }
    }
    if ($writefile) {
        // 写入配置文件
        set_addon_full_config($name, $fullconfig);
    }
    return true;
}

/**
 * 写入配置文件
 *
 * @param string $name 插件名
 * @param array $array
 * @return boolean
 * @throws Exception
 */
function set_addon_full_config($name, $array)
{
    $file = ADDON_PATH . $name . DIRECTORY_SEPARATOR . 'config.php';
    if (!is_really_writable($file)) {
        throw new Exception("文件没有写入权限");
    }
    if ($handle = fopen($file, 'w')) {
        fwrite($handle, "<?php\n\n" . "return " . var_export($array, true) . ";\n");
        fclose($handle);
    } else {
        throw new Exception("文件没有写入权限");
    }
    return true;
}
