<?php

namespace think\addons;

use think\Config;
use think\exception\HttpException;
use think\Hook;
use think\Loader;
use think\Request;

/**
 * 插件执行默认控制器
 * Class AddonsController
 * @package think\addons
 */
class Route
{

    /**
     * 插件执行
     */
    public function execute($addon = null, $controller = null, $action = null)
    {
        // 初始化request对像
        $request = Request::instance();

        // 是否自动转换控制器和操作名
        $convert = Config::get('url_convert');
        $filter = $convert ? 'strtolower' : 'trim';

        // 设置插件、控制器、方法
        $addon = $addon ? trim(call_user_func($filter, $addon)) : '';
        $controller = $controller ? trim(call_user_func($filter, $controller)) : 'index';
        $action = $action ? trim(call_user_func($filter, $action)) : 'index';

        // 插件初始化 app\\common\\behavior\\Common\\addonBegin
        Hook::listen('addon_begin', $request);

        if (!empty($addon) && !empty($controller) && !empty($action)) {
            // 获取插件信息
            $info = get_addon_info($addon);
            if (!$info) {
                throw new HttpException(404, L('addon %s not found', $addon));
            }

            // 插件已经停用
            if (!$info['state']) {
                throw new HttpException(500, L('addon %s is disabled', $addon));
            }

            // 设置路由参数
            $dispatch = $request->dispatch();
            if (isset($dispatch['var']) && $dispatch['var']) {
                //$request->route($dispatch['var']);
            }

            // 设置当前请求的控制器、操作
            $request->controller($controller)->action($action);

            // 监听插件模块初始化事件
            Hook::listen('addon_module_init', $request);

            // 获取插件类的类名
            $class = get_addon_class($addon, 'controller', $controller);
            if (!$class) {
                throw new HttpException(404, L('addon controller %s not found', Loader::parseName($controller, 1)));
            }

            // 实例化插件类
            $instance = new $class($request);

            $vars = [];
            if (is_callable([$instance, $action])) { // 检测插件方法是否可用
                $call = [$instance, $action]; // 插件要执行的方法
            } elseif (is_callable([$instance, '_empty'])) {
                $call = [$instance, '_empty']; // 空操作
                $vars = [$action];
            } else {
                // 操作不存在
                throw new HttpException(404, L('addon action %s not found', get_class($instance) . '->' . $action . '()'));
            }

            // 监听插件模块开始事件
            Hook::listen('addon_action_begin', $call);

            // 调用插件的方法，并把一个数组参数作为回调函数的参数
            return call_user_func_array($call, $vars);
        } else {
            abort(500, lang('addon can not be empty'));
        }

    }

}
