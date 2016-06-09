<?php
/*
 * Copyright 2007-2013 Charles du Jeu - Abstrium SAS <team (at) pyd.io>
 * This file is part of Pydio.
 *
 * Pydio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Pydio is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Pydio.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://pyd.io/>.
 */
namespace Pydio\Core\Services;

use Pydio\Access\Core\AbstractAccessDriver;
use Pydio\Access\Core\AJXP_MetaStreamWrapper;
use Pydio\Auth\Core\AbstractAuthDriver;
use Pydio\Cache\Core\AbstractCacheDriver;
use Pydio\Conf\Core\AbstractAjxpUser;
use Pydio\Conf\Core\AbstractConfDriver;
use Pydio\Core\Exception\PydioException;
use Pydio\Core\Model\Context;
use Pydio\Core\Model\RepositoryInterface;
use Pydio\Core\Model\UserInterface;
use Pydio\Core\PluginFramework\CoreInstanceProvider;
use Pydio\Core\Utils\Utils;
use Pydio\Core\Utils\VarsFilter;
use Pydio\Core\PluginFramework\Plugin;
use Pydio\Core\PluginFramework\PluginsService;

defined('AJXP_EXEC') or die( 'Access not allowed');

/**
 * Configuration holder. Singleton class accessed statically, encapsulates the confDriver implementation.
 * @package Pydio
 * @subpackage Core
 */
class ConfService
{
    private static $instance;
    public static $useSession = true;

    private $errors = array();
    private $configs = array();

    private $contextRepositoryId;
    private $contextCharset;


    /**
     * @return AbstractConfDriver
     */
    public static function getBootConfStorageImpl()
    {
        $inst = PluginsService::getInstance(Context::emptyContext())->getPluginById("boot.conf");
        if (empty($inst)) {
            $inst = PluginsService::getInstance(Context::emptyContext())->softLoad("boot.conf", array());
        }
        return $inst;
    }

    /**
     * Initialize singleton
     * @static
     * @param string $installPath
     * @param string $pluginDir
     */
    public static function init($installPath=AJXP_INSTALL_PATH, $pluginDir="plugins")
    {
        $inst = self::getInstance();
        $inst->initInst();
    }

    /**
     * Load the boostrap_* files and their configs
     * @return void
     */
    private function initInst()
    {
        // INIT AS GLOBAL
        if (isSet($_SERVER["HTTPS"]) && strtolower($_SERVER["HTTPS"]) == "on") {
            $this->configs["USE_HTTPS"] = true;
        }
        if (isSet($this->configs["USE_HTTPS"])) {
            Utils::safeIniSet("session.cookie_secure", true);
        }
        $this->configs["JS_DEBUG"] = AJXP_CLIENT_DEBUG;
        $this->configs["SERVER_DEBUG"] = AJXP_SERVER_DEBUG;

    }

    /**
     * Start the singleton
     * @static
     * @return void
     */
    public static function start()
    {
        $inst = self::getInstance();
        $inst->startInst();
        $confStorageDriver = self::getConfStorageImpl();
        require_once($confStorageDriver->getUserClassFileName());
    }
    /**
     * Init CONF, AUTH drivers
     * Init Repositories
     * @return void
     */
    public function startInst()
    {
        PluginsService::getInstance(Context::emptyContext())->setPluginUniqueActiveForType("conf", self::getConfStorageImpl()->getName());
    }
    /**
     * Get errors generated by the boot sequence (init/start)
     * @static
     * @return array
     */
    public static function getErrors()
    {
        return self::getInstance()->errors;
    }

    public static function getContextCharset(){
        if(self::$useSession) {
            if(isSet($_SESSION["AJXP_CHARSET"])) return $_SESSION["AJXP_CHARSET"];
            else return null;
        }else {
            return self::getInstance()->contextCharset;
        }
    }

    public static function setContextCharset($value){
        if(self::$useSession){
            $_SESSION["AJXP_CHARSET"] = $value;
        }else{
            self::getInstance()->contextCharset = $value;
        }
    }

    public static function clearContextCharset(){
        if(self::$useSession && isSet($_SESSION["AJXP_CHARSET"])){
            unset($_SESSION["AJXP_CHARSET"]);
        }else{
            self::getInstance()->contextCharset = null;
        }
    }

    public static function clearAllCaches(){
        PluginsService::clearPluginsCache();
        LocaleService::clearMessagesCache();
        CacheService::deleteAll(AJXP_CACHE_SERVICE_NS_SHARED);
        if(function_exists('opcache_reset')){
            opcache_reset();
        }
    }

    /**
     * @static
     * @param $globalsArray
     * @param string $interfaceCheck
     * @param PluginsService|null $pService
     * @return Plugin|null
     */
    public static function instanciatePluginFromGlobalParams($globalsArray, $interfaceCheck = "", $pService = null)
    {
        $plugin = false;
        if($pService === null){
            $pService = PluginsService::getInstance(Context::emptyContext());
        }

        if (is_string($globalsArray)) {
            $globalsArray = array("instance_name" => $globalsArray);
        }

        if (isSet($globalsArray["instance_name"])) {
            $pName = $globalsArray["instance_name"];
            unset($globalsArray["instance_name"]);

            $plugin = $pService->softLoad($pName, $globalsArray);
            $plugin->performChecks();
        }

        if ($plugin != false && !empty($interfaceCheck)) {
            if (!is_a($plugin, $interfaceCheck)) {
                $plugin = false;
            }
        }
        return $plugin;

    }

    /**
     * Check if the STDIN constant is defined
     * @static
     * @return bool
     */
    public static function currentContextIsCommandLine()
    {
        return php_sapi_name() === "cli";
    }

    protected static $restAPIContext;

    /**
     * Set or get if we are currently running REST
     * @static
     * @param string $restBase
     * @return bool
     */
    public static function currentContextIsRestAPI($restBase = '')
    {
        if(!empty($restBase)){
            self::$restAPIContext = $restBase;
            self::$useSession = false;
            AuthService::$useSession = false;
            return $restBase;
        }else{
            return self::$restAPIContext;
        }
    }

    /**
     * Check the presence of mcrypt and option CMDLINE_ACTIVE
     * @static
     * @return bool
     */
    public static function backgroundActionsSupported()
    {
        return function_exists("mcrypt_create_iv") && ConfService::getCoreConf("CMDLINE_ACTIVE");
    }

    /**
     * @var AbstractConfDriver
     */
    private static $tmpConfStorageImpl;
    /**
     * @var AbstractAuthDriver
     */
    private static $tmpAuthStorageImpl;
    /**
     * @var AbstractCacheDriver
     */
    private static $tmpCacheStorageImpl;

    /**
     * @param $confStorage AbstractConfDriver
     * @param $authStorage AbstractAuthDriver
     * @param $cacheStorage AbstractCacheDriver
     */
    public static function setTmpStorageImplementations($confStorage, $authStorage, $cacheStorage)
    {
        self::$tmpConfStorageImpl = $confStorage;
        self::$tmpAuthStorageImpl = $authStorage;
        self::$tmpCacheStorageImpl = $cacheStorage;
    }

    /**
     * Get conf driver implementation
     *
     * @return AbstractConfDriver
     */
    public static function getConfStorageImpl()
    {
        if(isSet(self::$tmpConfStorageImpl)) return self::$tmpConfStorageImpl;
        /** @var CoreInstanceProvider $p */
        $p = PluginsService::getInstance(Context::emptyContext())->getPluginById("core.conf");
        return $p->getImplementation();
    }

    /**
     * Get auth driver implementation
     *
     * @return AbstractAuthDriver
     */
    public static function getAuthDriverImpl()
    {
        if(isSet(self::$tmpAuthStorageImpl)) return self::$tmpAuthStorageImpl;
        /** @var CoreInstanceProvider $p */
        $p = PluginsService::getInstance(Context::emptyContext())->getPluginById("core.auth");
        return $p->getImplementation();
    }

    /**
     * Get auth driver implementation
     *
     * @return AbstractCacheDriver
     */
    public static function getCacheDriverImpl()
    {
        if(isSet(self::$tmpCacheStorageImpl)) return self::$tmpCacheStorageImpl;
        /**
         * Get CacheService implementation, directly from the "empty" plugin registry
         * @var CoreInstanceProvider $p
         */
        $p = PluginsService::getInstance(Context::emptyContext())->getPluginById("core.cache");
        return $p->getImplementation();
    }


    
    /**
     * See instance method
     * @static
     * @param $rootDirIndex
     * @param bool $temporary
     * @return RepositoryInterface
     */
    public static function switchRootDir($rootDirIndex, $temporary = false)
    {
        return self::getInstance()->switchRootDirInst($rootDirIndex, $temporary);
    }

    /**
     * Switch the current repository
     * @param int $rootDirIndex
     * @param bool $temporary
     * @throws PydioException
     * @return RepositoryInterface
     */
    public function switchRootDirInst($rootDirIndex=-1, $temporary=false)
    {
        // TMP
        $loggedUser = AuthService::getLoggedUser();

        $object = RepositoryService::getRepositoryById($rootDirIndex);
        if($temporary && ($object == null || !RepositoryService::repositoryIsAccessible($object, $loggedUser))) {
            throw new PydioException("Trying to switch to an unauthorized repository");
        }

        if (isSet($this->configs["REPOSITORIES"]) && isSet($this->configs["REPOSITORIES"][$rootDirIndex])) {
            $this->configs["REPOSITORY"] = $this->configs["REPOSITORIES"][$rootDirIndex];
        } else {
            $this->configs["REPOSITORY"] = RepositoryService::getRepositoryById($rootDirIndex);
        }
        if(self::$useSession){
            //$_SESSION['REPO_ID'] = $rootDirIndex;
        }else{
            $this->contextRepositoryId = $rootDirIndex;
        }
        if(isSet($this->configs["ACCESS_DRIVER"])) unset($this->configs["ACCESS_DRIVER"]);

        if (isSet($this->configs["REPOSITORY"]) && $this->configs["REPOSITORY"]->getSafeOption("CHARSET")!="") {
            self::setContextCharset($this->configs["REPOSITORY"]->getSafeOption("CHARSET"));
        } else {
            self::clearContextCharset();
        }


        if ($rootDirIndex!=-1 && UsersService::usersEnabled() && AuthService::getLoggedUser()!=null) {
            $loggedUser = AuthService::getLoggedUser();
            $loggedUser->setArrayPref("history", "last_repository", $rootDirIndex);
        }

        return $this->configs["REPOSITORY"];

    }



    public function getContextRepositoryId(){
        return self::$useSession ? $_SESSION["REPO_ID"] : $this->contextRepositoryId;
    }


    public function invalidateLoadedRepositories()
    {
        UsersService::invalidateCache();
        PluginsService::clearRegistryCaches();
    }


    /**
     * See instance method
     * @param UserInterface $user
     * @param bool $register
     * @return array
     */
    public static function detectRepositoryStreams(UserInterface $user, $register = false)
    {
        return self::getInstance()->detectRepositoryStreamsInst($user, $register);
    }
    
    /**
     * Call the detectStreamWrapper method
     * @param UserInterface $user
     * @param bool $register
     * @return array
     */
    public function detectRepositoryStreamsInst(UserInterface $user, $register = false)
    {
        $streams = array();
        $currentRepos = UsersService::getRepositoriesForUser($user);
        foreach ($currentRepos as $repository) {
            AJXP_MetaStreamWrapper::detectWrapperForRepository($repository,$register, $streams);
        }
        return $streams;
    }

    
    /**
     *  ZIP FEATURES
     */

    /**
     * Check if the gzopen function exists
     * @static
     * @return bool
     */
    public static function zipEnabled()
    {
        return (function_exists("gzopen") || function_exists("gzopen64"));
    }

    /**
     * Check if users are allowed to browse ZIP content
     * @static
     * @return bool
     */
    public static function zipBrowsingEnabled()
    {
        if(!self::zipEnabled()) return false;
        return !ConfService::getCoreConf("DISABLE_ZIP_BROWSING");
    }

    /**
     * Check if users are allowed to create ZIP archive
     * @static
     * @return bool
     */
    public static function zipCreationEnabled()
    {
        if(!self::zipEnabled()) return false;
        return ConfService::getCoreConf("ZIP_CREATION");
    }


    /**
     * MISC CONFS 
     */
    /**
     * Get all registered extensions, from both the conf/extensions.conf.php and from the plugins
     * @static
     * @return
     */
    public static function getRegisteredExtensions()
    {
        return self::getInstance()->getRegisteredExtensionsInst();
    }
    /**
     * See static method
     * @return
     */
    public function getRegisteredExtensionsInst()
    {
        if (!isSet($this->configs["EXTENSIONS"])) {
            $EXTENSIONS = array();
            $RESERVED_EXTENSIONS = array();
            include_once(AJXP_CONF_PATH."/extensions.conf.php");
            $EXTENSIONS = array_merge($RESERVED_EXTENSIONS, $EXTENSIONS);
            foreach ($EXTENSIONS as $key => $value) {
                unset($EXTENSIONS[$key]);
                $EXTENSIONS[$value[0]] = $value;
            }
            $nodes = PluginsService::getInstance(Context::emptyContext())->searchAllManifests("//extensions/extension", "nodes", true);
            $res = array();
            /** @var \DOMElement $node */
            foreach ($nodes as $node) {
                $res[$node->getAttribute("mime")] = array($node->getAttribute("mime"), $node->getAttribute("icon"), $node->getAttribute("messageId"));
            }
            if (count($res)) {
                $EXTENSIONS = array_merge($EXTENSIONS, $res);
            }
            $this->configs["EXTENSIONS"] = $EXTENSIONS;
        }
        return $this->configs["EXTENSIONS"];
    }
    /**
     * Get the actions that declare to skip the secure token in the plugins
     * @static
     * @return array
     */
    public static function getDeclaredUnsecureActions()
    {
        return PluginsService::searchManifestsWithCache("//action[@skipSecureToken]", function($nodes){
            $res = array();
            foreach ($nodes as $node) {
                $res[] = $node->getAttribute("name");
            }
            return $res;
        });
    }

    /**
     * Get a config by its name
     * @static
     * @param string $varName
     * @return mixed
     */
    public static function getConf($varName)
    {
        return self::getInstance()->getConfInst($varName);
    }
    /**
     * Set a config by its name
     * @static
     * @param string $varName
     * @param mixed $varValue
     * @return void
     */
    public static function setConf($varName, $varValue)
    {
        self::getInstance()->setConfInst($varName, $varValue);
    }
    /**
     * See static method
     * @param $varName
     * @return mixed
     */
    protected function getConfInst($varName)
    {
        if (isSet($this->configs[$varName])) {
            return $this->configs[$varName];
        }
        if (defined("AJXP_".$varName)) {
            return constant("AJXP_".$varName);
        }
        return null;
    }
    /**
     * See static method
     * @param $varName
     * @param $varValue
     * @return void
     */
    protected function setConfInst($varName, $varValue)
    {
        $this->configs[$varName] = $varValue;
    }
    /**
     * Get config from the core.$coreType plugin
     * @static
     * @param string $varName
     * @param string $coreType
     * @return mixed|null|string
     */
    public static function getCoreConf($varName, $coreType = "ajaxplorer")
    {
        $ctx = Context::fromGlobalServices();
        $coreP = PluginsService::getInstance($ctx)->findPlugin("core", $coreType);
        if($coreP === false) return null;
        $confs = $coreP->getConfigs();
        if($ctx->hasUser()){
            $confs = $ctx->getUser()->getMergedRole()->filterPluginConfigs("core".$coreType, $confs, $ctx->getRepositoryId());
        }
        return (isSet($confs[$varName]) ? VarsFilter::filter($confs[$varName], $ctx) : null);
    }

    /**
     * @var array Keep loaded labels in memory
     */
    private static $usersParametersCache = array();

    /**
     * @param string $parameterName Plugin parameter name
     * @param AbstractAjxpUser|string $userIdOrObject
     * @param string $pluginId Plugin name, core.conf by default
     * @param null $defaultValue
     * @return mixed
     */
    public static function getUserPersonalParameter($parameterName, $userIdOrObject, $pluginId="core.conf", $defaultValue=null){

        $cacheId = $pluginId."-".$parameterName;
        if(!isSet(self::$usersParametersCache[$cacheId])){
            self::$usersParametersCache[$cacheId] = array();
        }
        // Passed an already loaded object
        if($userIdOrObject instanceof AbstractAjxpUser){
            $value = $userIdOrObject->personalRole->filterParameterValue($pluginId, $parameterName, AJXP_REPO_SCOPE_ALL, $defaultValue);
            self::$usersParametersCache[$cacheId][$userIdOrObject->getId()] = $value;
            if(empty($value) && !empty($defaultValue)) $value = $defaultValue;
            return $value;
        }
        // Already in memory cache
        if(isSet(self::$usersParametersCache[$cacheId][$userIdOrObject])){
            return self::$usersParametersCache[$cacheId][$userIdOrObject];
        }

        // Try to load personal role if it was already loaded.
        $uRole = RolesService::getRole("AJXP_USR_/" . $userIdOrObject);
        if($uRole === false){
            $uObject = self::getConfStorageImpl()->createUserObject($userIdOrObject);
            if(isSet($uObject)){
                $uRole = $uObject->personalRole;
            }
        }
        if(empty($uRole)){
            return $defaultValue;
        }
        $value = $uRole->filterParameterValue($pluginId, $parameterName, AJXP_REPO_SCOPE_ALL, $defaultValue);
        if(empty($value) && !empty($defaultValue)) {
            $value = $userIdOrObject;
        }
        self::$usersParametersCache[$cacheId][$userIdOrObject] = $value;
        return $value;

    }
    

    /**
     * @static
     * @param RepositoryInterface $repository
     * @return AbstractAccessDriver
     */
    public static function loadDriverForRepository(&$repository)
    {
        return self::getInstance()->loadRepositoryDriverInst($repository);
    }

    /**
     * See static method
     * @param RepositoryInterface $repository
     * @throws PydioException|\Exception
     * @return AbstractAccessDriver
     */
    private function loadRepositoryDriverInst(&$repository)
    {
        $instance = $repository->getDriverInstance();
        if (!empty($instance)) {
            return $instance;
        }

        /** @var AbstractAccessDriver $plugInstance */
        $accessType = $repository->getAccessType();
        $pServ = PluginsService::getInstance();
        $plugInstance = $pServ->getPluginByTypeName("access", $accessType);

        /*
        $ctxId = $this->getContextRepositoryId();
        if ( (!empty($ctxId) || $ctxId === 0) && $ctxId == $repository->getId()) {
            $this->configs["REPOSITORY"] = $repository;
            $this->cacheRepository($ctxId, $repository);
        }
        */
        return $plugInstance;
    }
    
    
     /**
      * Singleton method
      *
      * @return ConfService the service instance
      */
     public static function getInstance()
     {
         if (!isSet(self::$instance)) {
             $c = __CLASS__;
             self::$instance = new $c;
         }
         return self::$instance;
     }
     private function __construct(){}
    public function __clone()
    {
        trigger_error("Cannot clone me, i'm a singleton!", E_USER_ERROR);
    }

}
