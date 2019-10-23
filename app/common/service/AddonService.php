<?php
/**
 * *
 *  * ============================================================================
 *  * Created by PhpStorm.
 *  * User: Ice
 *  * 邮箱: ice@sbing.vip
 *  * 网址: https://sbing.vip
 *  * Date: 2019/9/20 下午5:19
 *  * ============================================================================.
 */

namespace app\common\service;

use think\Service;
use think\facade\Env;
use think\facade\Cache;
use think\facade\Event;
use think\facade\Route;
use think\facade\Config;
use app\common\middleware\Addon;

/**
 * 插件服务
 */
class AddonService extends Service
{
    public function register()
    {
        // 插件目录
        define('ADDON_PATH', app()->getRootPath().'addons'.DIRECTORY_SEPARATOR);
        ! defined('DS') && define('DS', DIRECTORY_SEPARATOR);
        // 如果插件目录不存在则创建
        if (! is_dir(ADDON_PATH)) {
            @mkdir(ADDON_PATH, 0755, true);
        }
        //挂载插件服务
        $this->addon_service();
        //注册插件路由
        $this->addon_route();
        //注册插件事件
        $this->addon_event();
    }

    /**
     * 注册插件事件.
     */
    private function addon_event()
    {
        $hooks = Env::get('APP_DEBUG') ? [] : Cache::get('hooks', []);
        if (empty($hooks)) {
            $hooks = (array) Config::get('addons.hooks');
            // 初始化钩子
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
        //如果在插件中有定义app_init，则直接执行
        if (isset($hooks['app_init'])) {
            foreach ($hooks['app_init'] as $k => $v) {
                Event::trigger('app_init', $v);
            }
        }
        Event::listenEvents($hooks);
    }

    /**
     * 注册插件路由.
     */
    private function addon_route()
    {
        Route::rule('addons/:addon/[:controller]/[:action]', '\\think\\addons\\Route::execute')
            ->middleware(Addon::class);

        //注册路由
        $routeArr = (array) Config::get('addons.route');
        $execute = '\\think\\addons\\Route::execute';
        foreach ($routeArr as $k => $v) {
            if (is_array($v)) {
                $domain = $v['domain'];
                $drules = [];
                foreach ($v['rule'] as $m => $n) {
                    [$addon, $controller, $action] = explode('/', $n);
                    $drules[$m] = ['addon' => $addon, 'controller' => $controller, 'action' => $action, 'indomain' => 1];
                }
                Route::domain($domain, function () use ($drules, $execute) {
                    // 动态注册域名的路由规则
                    foreach ($drules as $k => $rule) {
                        Route::rule($k, $execute)
                            ->name($k)
                            ->completeMatch(true)
                            ->append($rule);
                    }
                });
            } else {
                if (! $v) {
                    continue;
                }
                [$addon, $controller, $action] = explode('/', $v);
                Route::rule($k, $execute)
                    ->name($k)
                    ->completeMatch(true)
                    ->append(['addon' => $addon, 'controller' => $controller, 'action' => $action]);
            }
        }
    }

    /**
     * 挂载插件内服务
     */
    private function addon_service()
    {
        $results = scandir(ADDON_PATH);
        $bind = [];
        foreach ($results as $name) {
            if ($name === '.' or $name === '..') {
                continue;
            }
            if (is_file(ADDON_PATH.$name)) {
                continue;
            }
            $addonDir = ADDON_PATH.$name.DIRECTORY_SEPARATOR;
            if (! is_dir($addonDir)) {
                continue;
            }

            if (! is_file($addonDir.ucfirst($name).'.php')) {
                continue;
            }

            $service_file = $addonDir.'service.ini';
            if (! is_file($service_file)) {
                continue;
            }
            $service = parse_ini_file($service_file, true, INI_SCANNER_TYPED) ?: [];
            $bind = array_merge($bind, $service);
        }
        $this->app->bind($bind);
    }
}