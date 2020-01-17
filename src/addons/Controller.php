<?php

namespace think\addons;

use app\common\library\Auth;
use think\Config;
use think\Hook;
use think\Lang;
use think\Loader;
use think\Request;

/**
 * 插件基类控制器
 * @package think\addons
 */
class Controller extends \think\Controller
{
    /**
     * 当前插件模块
     * @var string
     */
    protected $addon = null;

    /**
     * 当前插件控制器
     * @var string
     */
    protected $controller = null;

    /**
     * 当前插件方法
     * @var string
     */
    protected $action = null;

    /**
     * 当前视图模板
     * @var string
     */
    protected $template;

    /**
     * 无需登录的方法,同时也就不需要鉴权了
     * @var array
     */
    protected $noNeedLogin = ['*'];

    /**
     * 无需鉴权的方法,但需要登录
     * @var array
     */
    protected $noNeedRight = ['*'];

    /**
     * 权限Auth
     * @var Auth
     */
    protected $auth = null;

    /**
     * 布局模板
     * @var string
     */
    protected $layout = null;

    /**
     * 默认配置
     * @var array
     */
    protected $options = [
        'auth_on' => 0, // 权限开关
        'auth_table' => '', // 用户信息表
        'auth_rules' => '', // 权限规则表
        'auth_group' => '', // 用户组表名
        'auth_token' => '', // 用户令牌表
    ];

    /**
     * 架构函数
     * @param Request $request Request对象
     * @access public
     */
    public function __construct(Request $request = null)
    {
        // 生成request对象
        if (is_null($request)) {
            $request = Request::instance();
        }
        $this->request = $request;

        //移除HTML标签
        $this->request->filter('strip_tags');

        // 是否自动转换控制器和操作名
        $convert = Config::get('url_convert');
        $filter = $convert ? 'strtolower' : 'trim';

        // 处理路由参数
        $param = $this->request->param();
        //Array ( [rewrite] => product )
        //print_r($param);
        $dispatch = $this->request->dispatch();
        //Array ( [type] => method [method] => Array ( [0] => \think\addons\Route [1] => execute ) [var] => Array ( [addon] => article [controller] => channel [action] => index ) )
        //print_r($dispatch);
        $var = isset($dispatch['var']) ? $dispatch['var'] : [];
        $var = array_merge($param, $var);
        //Array ( [rewrite] => product [addon] => article [controller] => channel [action] => index )
        //print_r($var);
        if (isset($dispatch['method']) && substr($dispatch['method'][0], 0, 7) == "\\addons") {
            $arr = explode("\\", $dispatch['method'][0]);
            $addon = strtolower($arr[2]);
            $controller = strtolower(end($arr));
            $action = $dispatch['method'][1];
        } else {
            $addon = isset($var['addon']) ? $var['addon'] : '';
            $controller = isset($var['controller']) ? $var['controller'] : '';
            $action = isset($var['action']) ? $var['action'] : '';
        }

        $this->addon = $addon ? call_user_func($filter, $addon) : '';
        $this->controller = $controller ? call_user_func($filter, $controller) : 'index';
        $this->action = $action ? call_user_func($filter, $action) : 'index';

        // 重置模板路径
        Config::set('template.view_path', ADDON_PATH . $this->addon . DS . 'view' . DS);

        // 父类的调用必须放在设置模板路径之后
        parent::__construct($this->request);
    }

    /**
     * 初始化函数
     * @param
     * @access protected
     */
    protected function _initialize()
    {
        // 渲染配置到视图中
        $config = get_addon_config($this->addon);
        $this->view->assign("config", $config);

        // 加载系统语言包
        Lang::load([ADDON_PATH . $this->addon . DS . 'lang' . DS . $this->request->langset() . EXT]);

        // 设置替换字符串
        $resource = Config::get('site.resource');
        $this->view->replace('__ADDON__', $resource . "/static/addons/" . $this->addon);

        // 初始化权限类
        $this->auth = Auth::instance($this->options);

        // token
        $token = $this->request->server('HTTP_TOKEN', $this->request->request('token', \think\Cookie::get('token')));

        // 设置当前请求的URI
        $path = 'addons/' . $this->addon . '/' . str_replace('.', '/', $this->controller) . '/' . $this->action;
        $this->auth->setRequestUri($path);

        // 检测是否需要验证登录
        if (!$this->auth->match($this->noNeedLogin)) {
            //初始化
            $this->auth->init($token);

            //检测是否登录
            if (!$this->auth->isLogin()) {
                $this->error(L('Please login first'));
            }

            // 判断是否需要验证权限
            if (!$this->auth->match($this->noNeedRight)) {
                // 判断控制器和方法判断是否有对应权限
                if (!$this->auth->check($path)) {
                    $this->error(L('You have no permission'));
                }
            }
        } else {
            // 如果有传递token才验证是否登录状态
            if ($token) {
                $this->auth->init($token);
            }
        }

        // 如果有使用模板布局
        if ($this->layout) {
            $this->view->engine->layout('layout/' . $this->layout);
        }

        // 用户数据
        $this->view->assign('user', $this->auth->getUser());

        // 上传信息配置后
        $upload = \app\common\model\Config::upload();
        Hook::listen("upload_config_init", $upload);
        Config::set('upload', array_merge(Config::get('upload'), $upload));

        // 加载当前控制器语言包
        $site = Config::get("site");
        $this->assign('site', $site);

    }

    /**
     * 加载模板输出
     * @access protected
     * @param string $template 模板文件名
     * @param array $vars 模板输出变量
     * @param array $replace 模板替换
     * @param array $config 模板参数
     * @return mixed
     */
    protected function fetch($template = '', $vars = [], $replace = [], $config = [])
    {
        $controller = Loader::parseName($this->controller);
        // 模板引擎类型 支持 php think 支持扩展
        if ('think' == strtolower(Config::get('template.type')) && $controller && 0 !== strpos($template, '/')) {
            // 模板文件名分隔符
            $depr = Config::get('template.view_depr');
            $template = str_replace(['/', ':'], $depr, $template);
            if ('' == $template) {
                // 如果模板文件名为空 按照默认规则定位
                $template = str_replace('.', DS, $controller) . $depr . $this->action;
            } elseif (false === strpos($template, $depr)) {
                $template = str_replace('.', DS, $controller) . $depr . $template;
            }
        }
        return parent::fetch($template, $vars, $replace, $config);
    }

}
