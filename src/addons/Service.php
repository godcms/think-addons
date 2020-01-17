<?php
namespace think\addons;

use app\common\library\Http;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use think\Db;
use think\Exception;
use ZipArchive;


/**
 * 插件服务
 * @package think\addons
 */
class Service
{

	/**
     * 远程下载插件
     *
     * @param   string $name 插件名称
     * @param   array $extend 扩展参数
     * @return  string
     * @throws  AddonException
     * @throws  Exception
     */
    public static function download($name, $extend = [])
	{
		// 插件临时目录
		$tmpDir = RUNTIME_PATH . 'addons' . DS;
		if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }	

		// 插件临时文件
		$tmpFile = $tmpDir . $name . ".zip";
		if(is_file($tmpFile)){
			@unlink($tmpFile);
		}
		
		// 设置下载参数
		$options = [
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            //CURLOPT_HTTPHEADER     => ['X-REQUESTED-WITH: XMLHttpRequest'] //Ajax 异步请求
        ];
		
		// 下载插件安装包
		$ret = Http::sendRequest(self::getServerUrl() . '/plugins/download', array_merge(['name' => $name], $extend), 'GET', $options);
		if ($ret['code']==0 && $ret['msg']=='success') {
			// 传回的是JSON数据
			$json = (array)json_decode($ret['data'], true);
			// 返回 JSON 编码解码时最后发生的错误:0 = JSON_ERROR_NONE 没有错误
			if(json_last_error()==JSON_ERROR_NONE){
				if ($json['code']==0){
					//下载安装包文件
					$ret = Http::sendRequest($json['data'], [], 'GET', $options);
					if ($ret['code']!=0 && $ret['msg']!='success') {
						// 下载还是错误，抛出异常
						throw new AddonException($ret['msg'], $ret['code'], $ret['data']);
					}
				} else {
					//返回错误信息，抛出异常
					throw new AddonException($json['msg'], $json['code'], $json['data']);
				}
			}
			
			// 写入插件临时文件
			if ($write = fopen($tmpFile, 'w')) {
                fwrite($write, $ret['data']);
                fclose($write);
                return $tmpFile; // 返回插件临时文件地址
            } 
			
			throw new Exception("No permission to write files");
		}
		
		throw new Exception("Unable to download files");
	}
	
	
	/**
     * 解压插件
     *
     * @param   string $name 插件名称
     * @return  string
     * @throws  Exception
     */
    public static function unzip($name)
	{
		$file = RUNTIME_PATH . 'addons' . DS . $name . '.zip';
		$dir = ADDON_PATH . $name . DS;
		if (class_exists('ZipArchive')) {
			$zip = new ZipArchive;
            if ($zip->open($file) !== TRUE) {
                throw new Exception('Unable to open the zip file');
            }
            if (!$zip->extractTo($dir)) {
                $zip->close();
                throw new Exception('Unable to extract the file');
            }
            $zip->close();
            return $dir;
		}
		throw new Exception("无法执行解压操作，请确保ZipArchive安装正确");
	}
	
	
	/**
     * 备份插件
     * @param string $name 插件名称
     * @return bool
     * @throws Exception
     */
    public static function backup($name)
	{
		$file = RUNTIME_PATH . 'addons' . DS . $name . '-backup-' . date("YmdHis") . '.zip';
        $dir = ADDON_PATH . $name . DS;
		
        if (class_exists('ZipArchive')) {
			
            $zip = new ZipArchive;
            $zip->open($file, ZipArchive::CREATE);
			
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            
			foreach ($files as $fileinfo) {
                $filePath = $fileinfo->getPathName();
                $localName = str_replace($dir, '', $filePath);
				
                if ($fileinfo->isFile()) 
				{
                    $zip->addFile($filePath, $localName);
                } 
				elseif ($fileinfo->isDir()) 
				{
                    $zip->addEmptyDir($localName);
                }
            }
			
            $zip->close();
            return true;
        }
        throw new Exception("无法执行压缩操作，请确保ZipArchive安装正确");
	}
	
	
	/**
     * 检测插件是否完整
     *
     * @param   string $name 插件名称
     * @return  boolean
     * @throws  Exception
     */
    public static function check($name)
	{
		if (!$name || !is_dir(ADDON_PATH . $name)) {
            throw new Exception('Addon not exists');
        }
		
		$addonClass = get_addon_class($name);
        if (!$addonClass) {
            throw new Exception("Addon main class does not exist");
        }
		
		$addon = new $addonClass();
        if (!$addon->checkInfo()) {
            throw new Exception("Configuration file incomplete");
        }
		
        return true;
	}
	
	
	/**
     * 是否有冲突
     *
     * @param   string $name 插件名称
     * @return  boolean
     * @throws  AddonException
     */
    public static function noconflict($name)
	{
		// 获取插件在全局的文件
        $list = self::getGlobalFiles($name, true);
        if ($list) {
            //发现冲突文件，抛出异常
            throw new AddonException("发现冲突文件", -3, ['conflictlist' => $list]);
        }
        return true;
	}
	
	
	/**
     * 导入SQL
     *
     * @param   string $name 插件名称
     * @return  boolean
     */
    public static function importsql($name, $file = 'install.sql')
	{
		$sqlFile = ADDON_PATH . $name . DS . $file;
        if (is_file($sqlFile)) {
			$lines = file($sqlFile);
            $templine = '';
			foreach ($lines as $line) {
				if ((substr($line, 0, 2) == '--') || ($line == '') || (substr($line, 0, 2) == '/*')){
                    continue;
				}
				//*/
				$templine .= $line;
				if (substr(trim($line), -1, 1) == ';') {
                    $templine = str_ireplace('__PREFIX__', config('database.prefix'), $templine);
                    $templine = str_ireplace('INSERT INTO ', 'INSERT IGNORE INTO ', $templine);
                    try {
                        Db::getPdo()->exec($templine);
                    } catch (\PDOException $e) {
                        //$e->getMessage();
                    }
                    $templine = '';
                }
			}
		}
		return true;
	}
	
	
	/**
     * 刷新插件缓存文件
     *
     * @return  boolean
     * @throws  Exception
     */
    public static function refresh()
	{
		// 删除插件临时缓存(runtime/addons)
		rmdirs(RUNTIME_PATH . 'addons' . DS, false);
		
		// 刷新addons.js
        $addons = get_addon_list();
        $bootstrapArr = [];
		foreach ($addons as $name => $addon) {
			$bootstrapFile = ADDON_PATH . $name . DS . 'bootstrap.js';
			if ($addon['state'] && is_file($bootstrapFile)) {
                $bootstrapArr[] = file_get_contents($bootstrapFile);
            }
		}
		
		// 合并插件的bootstrap.js代码到addons.js文件中
		$addonsFile = ROOT_PATH . str_replace("/", DS, "public/static/js/addons.js");
		if ($handle = fopen($addonsFile, 'w')) {
			$tpl = <<<EOD
define([], function () {
    {__JS__}
});
EOD;
			fwrite($handle, str_replace("{__JS__}", implode("\n", $bootstrapArr), $tpl));
            fclose($handle);
		} else {
			throw new Exception("addons.js文件没有写入权限");
		}
		
		// 设置插件自动加载的配置
		$file = APP_PATH . 'extra' . DS . 'addons.php';
		
		// 获得插件自动加载的配置
		$config = get_addon_autoload_config(true);
        if ($config['autoload']){
            return true;
		}
		
		// 判断文件或文件夹是否可写
		if (!is_really_writable($file)) {
            throw new Exception("addons.php文件没有写入权限");
        }
		
		// 写入需要自动加载的配置
		if ($handle = fopen($file, 'w')) {
            fwrite($handle, "<?php\n\n" . "return " . var_export($config, TRUE) . ";");
            fclose($handle);
        } else {
            throw new Exception("文件没有写入权限");
        }
		
		return true;
	}
	
	/**
     * 安装插件
     *
     * @param   string $name 插件名称
     * @param   boolean $force 是否覆盖
     * @param   array $extend 扩展参数
     * @return  boolean
     * @throws  Exception
     * @throws  AddonException
     */
    public static function install($name, $force = false, $extend = [])
	{
		// 插件已经存在
		if (!$name || (is_dir(ADDON_PATH . $name) && !$force)) {
            throw new Exception('Addon already exists');
        }

		// 远程下载插件
        $tmpFile = Service::download($name, $extend);
		
		// 解压插件
        $addonDir = Service::unzip($name);
		
		// 移除临时文件
        @unlink($tmpFile);
		
		
		// 校验插件
		try {
            // 检查插件是否完整
            Service::check($name);
			// 是否有冲突
            if (!$force) {
                Service::noconflict($name);
            }
        } catch (AddonException $e) {
            @rmdirs($addonDir);
            throw new AddonException($e->getMessage(), $e->getCode(), $e->getData());
        } catch (Exception $e) {
            @rmdirs($addonDir);
            throw new Exception($e->getMessage());
        }
		
		// 复制插件的资源文件到(public/static/addons/)插件目录下。
        $sourceAssetsDir = self::getSourceStaticDir($name);
        $destAssetsDir = self::getDestStaticDir($name);
        if (is_dir($sourceAssetsDir)) {
            copydirs($sourceAssetsDir, $destAssetsDir);
        }
        foreach (self::getCheckDirs() as $k => $dir) {
            if (is_dir($addonDir . $dir)) {
                copydirs($addonDir . $dir, ROOT_PATH . $dir);
            }
        }
		
		// 插件安装初始化
		try {
            // 默认启用该插件
            $info = get_addon_info($name);
            if (!$info['state']) {
                $info['state'] = 1;
                set_addon_info($name, $info);
            }

            // 执行安装脚本
            $class = get_addon_class($name);
            if (class_exists($class)) {
                $addon = new $class();
                $res = $addon->install();
				if($res !== true) {
					$info['state'] = 0;
					set_addon_info($name, $info);
					throw new Exception($res);
				}
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
		
		// 导入
        Service::importsql($name,'install.sql');

        // 刷新
        Service::refresh();
		
        return true;
	}
	
	
	/**
     * 卸载插件
     *
     * @param   string $name
     * @param   boolean $force 是否强制卸载
     * @return  boolean
     * @throws  Exception
     */
    public static function uninstall($name, $force = false)
	{
		if (!$name || !is_dir(ADDON_PATH . $name)) {
            throw new Exception('Addon not exists');
        }
		
		// 检测插件文件和全局文件否有冲突
		if (!$force) {
            Service::noconflict($name);
        }
		
		// 获取插件目标资源文件夹
        $destAssetsDir = self::getDestStaticDir($name);
        if (is_dir($destAssetsDir)) {
			// 移除插件基础资源目录
            rmdirs($destAssetsDir);
        }
	
		// 移除插件全局资源文件
        if ($force) {
            $list = Service::getGlobalFiles($name);
            foreach ($list as $k => $v) {
				// 删除文件
                @unlink(ROOT_PATH . $v);
				
				// 删除目录（如果目录为空）
				@rmdir(dirname(ROOT_PATH . $v));
            }
        }
		
		// 执行卸载脚本
        try {
            $class = get_addon_class($name);
            if (class_exists($class)) {
                $addon = new $class();
                $addon->uninstall();
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
		
		// 删除数据库表
		Service::importsql($name,'uninstall.sql');
		
		// 移除插件目录
        rmdirs(ADDON_PATH . $name);

        // 刷新
        Service::refresh();
		
        return true;		
	}
	
	
	/**
     * 启用
     * @param   string $name 插件名称
     * @param   boolean $force 是否强制覆盖
     * @return  boolean
     */
    public static function enable($name, $force = false)
	{
		if (!$name || !is_dir(ADDON_PATH . $name)) {
            throw new Exception('Addon not exists');
        }
		
		// 检测插件文件和全局文件否有冲突
		if (!$force) {
            Service::noconflict($name);
        }
		
		// 插件目录
		$addonDir = ADDON_PATH . $name . DS;
		
		// 复制插件的资源文件到(public/static/addons/)插件目录下。
        $sourceAssetsDir = self::getSourceStaticDir($name);
        $destAssetsDir = self::getDestStaticDir($name);
        if (is_dir($sourceAssetsDir)) {
            copydirs($sourceAssetsDir, $destAssetsDir);
        }
		
		// 复制插件的全局文件夹到全局文件夹目录
        foreach (self::getCheckDirs() as $k => $dir) {
            if (is_dir($addonDir . $dir)) {
                copydirs($addonDir . $dir, ROOT_PATH . $dir);
            }
        }
		
		// 执行启用脚本
        try {
            $class = get_addon_class($name);
            if (class_exists($class)) {
                $addon = new $class();
                if (method_exists($class, "enable")) {
					$res = $addon->enable();
                    if($res !== true){
						throw new Exception($res);
					}
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
		
		// 设置插件的状态信息
		$info = get_addon_info($name);
        $info['state'] = 1;
        unset($info['route']);
        set_addon_info($name, $info);
		
		// 刷新所有插件缓存文件
        Service::refresh();
		
        return true;
	}
	
	
	/**
     * 禁用
     *
     * @param   string $name 插件名称
     * @param   boolean $force 是否强制禁用
     * @return  boolean
     * @throws  Exception
     */
    public static function disable($name, $force = false)
	{
		if (!$name || !is_dir(ADDON_PATH . $name)) {
            throw new Exception('Addon not exists');
        }
		
		// 检测插件文件和全局文件否有冲突
        if (!$force) {
            Service::noconflict($name);
        }
		
		// 移除插件基础资源目录
        $destAssetsDir = self::getDestStaticDir($name);
        if (is_dir($destAssetsDir)) {
            rmdirs($destAssetsDir);
        }
		
		// 移除插件全局资源文件
        $list = Service::getGlobalFiles($name);
        foreach ($list as $k => $v) {
			// 删除文件
			@unlink(ROOT_PATH . $v);
			
			// 删除目录（如果目录为空）
			@rmdir(dirname(ROOT_PATH . $v));
        }
		
		// 设置插件的状态信息
		$info = get_addon_info($name);
        $info['state'] = 0;
        unset($info['route']);
		set_addon_info($name, $info);
		
		// 执行禁用脚本
        try {
            $class = get_addon_class($name);
            if (class_exists($class)) {
                $addon = new $class();
                if (method_exists($class, "disable")) {
                    $addon->disable();
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        // 刷新所有插件缓存文件
        Service::refresh();
		
        return true;
	}
	
	
	
	/**
     * 升级插件
     *
     * @param   string $name 插件名称
     * @param   array $extend 扩展参数
     */
    public static function upgrade($name, $extend = [])
	{
		$info = get_addon_info($name);
        if ($info['state']) {
            throw new Exception(L('Please disable addon first'));
        }
		
        $config = get_addon_config($name);
        if ($config) {
            //备份配置
        }
		
		// 备份插件文件
        Service::backup($name);
		
		// 远程下载插件
        $tmpFile = Service::download($name, $extend);

        // 解压插件
        $addonDir = Service::unzip($name);
		
		// 移除临时文件
        @unlink($tmpFile);
		
		if ($config) {
            // 还原配置
            set_addon_config($name, $config);
        }
		
		// 导入
        Service::importsql($name,'upgrade.sql');
		
		// 执行升级脚本
        try {
            $class = get_addon_class($name);
            if (class_exists($class)) {
                $addon = new $class();

                if (method_exists($class, "upgrade")) {
                    $addon->upgrade();
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
		
		// 刷新
        Service::refresh();

        return true;
	}
	
	
	/**
     * 获取插件在全局的文件
     *
     * @param   string $name 插件名称
	 * @param   boolean $onlyconflict 是否检查文件冲突(插件目录的文件和全局目录的文件不一样)
     * @return  array
     */
    public static function getGlobalFiles($name, $onlyconflict = false)
	{
		$list = [];
        $addonDir = ADDON_PATH . $name . DS;
        // 扫描插件目录是否有覆盖的文件 ['application','public'];
        foreach (self::getCheckDirs() as $k => $dir) {
			
            $checkDir = ROOT_PATH . DS . $dir . DS;
            if (!is_dir($checkDir)){
                continue;
			}
			
            //检测到存在插件外目录
            if (is_dir($addonDir . $dir)) {
				
                //匹配出插件['application','public']目录下所有的目录和文件
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($addonDir . $dir, RecursiveDirectoryIterator::SKIP_DOTS), 
					RecursiveIteratorIterator::CHILD_FIRST
				);
				
				//遍历所有文件
                foreach ($files as $fileinfo) {
                    if ($fileinfo->isFile()) {
						//获取插件文件的完整路径
                        $filePath = $fileinfo->getPathName();
						//去掉插件目录
                        $path = str_replace($addonDir, '', $filePath); 
						//检测文件和全局文件是否一样
                        if ($onlyconflict) {
                            $destPath = ROOT_PATH . $path;
                            if (is_file($destPath)) {
                                if (filesize($filePath) != filesize($destPath) || md5_file($filePath) != md5_file($destPath)) {
                                    $list[] = $path;
                                }
                            }
                        } else {
                            $list[] = $path;
                        }
                    }
                }
            }
        }
        return $list;
	}
	
	
	/**
     * 获取插件源资源文件夹
     * @param   string $name 插件名称
     * @return  string
     */
    protected static function getSourceStaticDir($name)
	{
		return ADDON_PATH . $name . DS . 'static' . DS;
	}
	
	
	/**
     * 获取插件目标资源文件夹
     * @param   string $name 插件名称
     * @return  string
     */
    protected static function getDestStaticDir($name)
	{
		$assetsDir = ROOT_PATH . str_replace("/", DS, "public/static/addons/{$name}/");
        if (!is_dir($assetsDir)) {
            mkdir($assetsDir, 0755, true);
        }
        return $assetsDir;
	}
	
	
	/**
     * 获取远程服务器
     * @return  string
     */
    protected static function getServerUrl()
	{
		return config('siteadmin.api_url');
	}
	
	
	/**
     * 获取检测的全局文件夹目录
     * @return  array
     */
    protected static function getCheckDirs()
	{
		return ['application','public'];
	}
	
}
