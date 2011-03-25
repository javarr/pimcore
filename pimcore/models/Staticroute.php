<?php
/**
 * Pimcore
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.pimcore.org/license
 *
 * @category   Pimcore
 * @package    Staticroute
 * @copyright  Copyright (c) 2009-2010 elements.at New Media Solutions GmbH (http://www.elements.at)
 * @license    http://www.pimcore.org/license     New BSD License
 */

class Staticroute extends Pimcore_Model_Abstract {

    /**
     * @var integer
     */
    public $id;
    
    /**
     * @var string
     */
    public $name;
    
    /**
     * @var string
     */
    public $pattern;
    
    /**
     * @var string
     */
    public $reverse;
    
    /**
     * @var string
     */
    public $controller;

    /**
     * @var string
     */
    public $action;

    /**
     * @var string
     */
    public $variables;

    /**
     * @var string
     */
    public $defaults;

    /**
     * @var integer
     */
    public $priority;

    /**
     * @param integer $id
     * @return Staticroute
     */
    public static function getById($id) {
        
        try {
            $route = new self();
            $route->setId(intval($id));
            $route->getResource()->getById();
    
            return $route;
        } catch (Exception $e) {
            Logger::warning($e);
        }
        
        return;
    }
    
    /**
     * @param string $name
     * @return Staticroute
     */
    public static function getByName($name) {
        
        try {
            $route = new self();
            $route->setName($name);
            $route->getResource()->getByName();
    
            return $route;
        } catch (Exception $e) {
            Logger::warning($e);
        }
        
        return;
    }

    /**
     * @return Staticroute
     */
    public static function create() {
        $route = new self();
        $route->save();

        return $route;
    }

    /**
     * Get the defaults defined in a string as array
     *
     * @return array
     */
    public function getDefaultsArray() {
        $defaults = array();

        $t = explode("|", $this->getDefaults());
        foreach ($t as $v) {
            $d = explode("=", $v);
            if (strlen($d[0]) > 0 && strlen($d[1]) > 0) {
                $defaults[$d[0]] = $d[1];
            }
        }

        return $defaults;
    }

    /**
     * @return integer
     */
    public function getId() {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getPattern() {
        return $this->pattern;
    }

    /**
     * @return string
     */
    public function getController() {
        return $this->controller;
    }

    /**
     * @return string
     */
    public function getAction() {
        return $this->action;
    }

    /**
     * @return string
     */
    public function getVariables() {
        return $this->variables;
    }

    /**
     * @return string
     */
    public function getDefaults() {
        return $this->defaults;
    }

    /**
     * @param integer $id
     * @return void
     */
    public function setId($id) {
        $this->id = $id;
    }

    /**
     * @param string $pattern
     * @return void
     */
    public function setPattern($pattern) {
        $this->pattern = $pattern;
    }

    /**
     * @param string $controller
     * @return void
     */
    public function setController($controller) {
        $this->controller = $controller;
    }

    /**
     * @param string $action
     * @return void
     */
    public function setAction($action) {
        $this->action = $action;
    }

    /**
     * @param string $variables
     * @return void
     */
    public function setVariables($variables) {
        $this->variables = $variables;
    }

    /**
     * @param string $defaults
     * @return void
     */
    public function setDefaults($defaults) {
        $this->defaults = $defaults;
    }

    /**
     * @param integer $priority
     * @return void
     */
    public function setPriority($priority) {
        $this->priority = $priority;
    }

    /**
     * @return integer
     */
    public function getPriority() {
        return $this->priority;
    }
    
    /**
     * @param string $name
     * @return void
     */
    public function setName($name) {
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getName() {
        return $this->name;
    }
    
    /**
     * @param string $reverse
     * @return void
     */
    public function setReverse($reverse) {
        $this->reverse = $reverse;
    }

    /**
     * @return string
     */
    public function getReverse() {
        return $this->reverse;
    }
    
    /**
     * @param array $urlOptions
     * @return string
     */
    public function assemble (array $urlOptions = array(), $reset=false, $encode=true) {

        // get request parameters
        $blockedRequestParams = array("controller","action","module","document");
        $front = Zend_Controller_Front::getInstance();
        $requestParameters = $front->getRequest()->getParams();
        // remove blocked parameters from request
        foreach ($blockedRequestParams as $key) {
            if(array_key_exists($key, $requestParameters)) {
                unset($requestParameters[$key]);
            }
        }

        // reset request parameters
        if($reset) {
            $requestParameters = array();
        }


        $urlParams = array_merge($requestParameters, $urlOptions);
        $parametersInReversePattern = array();
        $parametersGet = array();
        $parametersNotNamed = array();
        $url = $this->getReverse();

        // check for named variables
        foreach ($urlParams as $key => $param) {
            if(strpos($this->getReverse(), "%" . $key) !== false) {
                $parametersInReversePattern[$key] = $param;
            } else if (is_numeric($key)) {
                $parametersNotNamed[$key] = $param;
            } else {
                // only append the get parameters if there are defined in $urlOptions
                if(array_key_exists($key,$urlOptions)) {
                    $parametersGet[$key] = $param;
                }
            }
        }

        // replace named variables
        foreach ($parametersInReversePattern as $key => $value) {
            if(!empty($value)) {
                $url = str_replace("%".$key, urlencode($value), $url);
            }
        }


        // not named parameters
        $o = array();
        foreach ($parametersNotNamed as $option) {
            $o[] = urlencode($option);
        }

        // remove optional parts
        $url = preg_replace("/\{.*%.*\}/","",$url);
        $url = str_replace(array("{","}"),"",$url);

        $url = @vsprintf($url,$o);
        if(empty($url)) {
            $url = "ERROR_IN_YOUR_URL_CONFIGURATION:~ONE_PARAMETER_IS_MISSING_TO_GENERATE_THE_URL";
            return $url;
        }

        // optional get parameters
        $getParams = array_urlencode($parametersGet);
        if(!empty($getParams)) {
            $url .= "?" . $getParams;
        }

        return $url;
    }
    
    
    /**
     * @return void
     */
    public function clearDependedCache() {
        
        // this is mostly called in Staticroute_Resource_Mysql not here
        try {
            Pimcore_Model_Cache::clearTag("staticroute");
        }
        catch (Exception $e) {
            Logger::info($e);
        }
    }
}