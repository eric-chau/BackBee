<?php

/*
 * Copyright (c) 2011-2015 Lp digital system
 *
 * This file is part of BackBee.
 *
 * BackBee is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * BackBee is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with BackBee. If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Charles Rouillon <charles.rouillon@lp-digital.fr>
 */

namespace BackBee\Renderer;

use BackBee\NestedNode\Page;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\Security\Core\Util\ClassUtils;

use BackBee\BBApplication;
use BackBee\Renderer\Event\RendererEvent;
use BackBee\Renderer\Exception\RendererException;
use BackBee\Site\Layout;
use BackBee\Site\Site;
use BackBee\Utils\File\File;
use BackBee\Utils\StringUtils;
use BackBee\ClassContent\AbstractClassContent;

/**
 * Abstract class for a renderer.
 *
 * @category    BackBee
 *
 * @copyright Lp digital system
 * @author    c.rouillon <charles.rouillon@lp-digital.fr>
 * @author    Eric Chau <eric.chau@lp-digital.fr>
 */
abstract class AbstractRenderer implements RendererInterface
{
    /**
     * Current BackBee application.
     *
     * @var BackBee\BBApplication
     */
    protected $application;

    /**
     * Contains evey helpers.
     *
     * @var ParameterBag
     */
    protected $helpers;

    /**
     * The current object to be render.
     *
     * @var RenderableInterface
     */
    protected $_object;

    /**
     * The current page to be render.
     *
     * @var BackBee\NestedNode\Page
     */
    protected $_currentpage;

    /** The rendering mode
     * @var string
     */
    protected $_mode;

    /**
     * Ignore the rendering if specified render mode is not
     * available if TRUE, use the default template otherwise.
     *
     * @var Boolean
     */
    protected $_ignoreIfRenderModeNotAvailable;
    protected $_node;

    /**
     * The content parameters.
     *
     * @var array
     */
    protected $_params = [];
    protected $_parentuid;

    /**
     * The file path to look for templates.
     *
     * @var array
     */
    protected $_scriptdir = [];

    /**
     * The file path to look for layouts.
     *
     * @var array
     */
    protected $_layoutdir = [];

    /**
     * Extensions to include searching file.
     *
     * @var array
     */
    protected $_includeExtensions = [];

    /**
     * The assigned variables.
     *
     * @var array
     */
    protected $_vars = [];
    protected $__currentelement;
    protected $__object = null;
    private $__vars = [];
    private $__overloaded = 0;
    protected $__render;

    /**
     * Class constructor.
     *
     * @param BBAplication $application The current BBapplication
     * @param array        $config      Optional configurations overriding
     */
    public function __construct(BBApplication $application, $config = null)
    {
        $this->application = $application;

        $rendererConfig = $this->application->getConfig()->getRendererConfig();
        if (is_array($rendererConfig) && isset($rendererConfig['path'])) {
            $config = $config ? array_merge_recursive($config, $rendererConfig['path']) : $rendererConfig['path'];
        }

        if (is_array($config)) {
            if (isset($config['scriptdir'])) {
                $dirs = (array) $config['scriptdir'];
                array_walk(
                    $dirs,
                    ['\BackBee\Utils\File\File', 'resolveFilepath'],
                    ['base_dir' => $this->getApplication()->getRepository()]
                );

                foreach ($dirs as $dir) {
                    if (file_exists($dir) && is_dir($dir)) {
                        $this->_scriptdir[] = $dir;
                    }
                }

                if ($this->getApplication()->hasContext()) {
                    $dirs = (array) $config['scriptdir'];
                    array_walk(
                        $dirs,
                        ['\BackBee\Utils\File\File', 'resolveFilepath'],
                        ['base_dir' => $this->getApplication()->getBaseRepository()]
                    );

                    foreach ($dirs as $dir) {
                        if (file_exists($dir) && is_dir($dir)) {
                            $this->_scriptdir[] = $dir;
                        }
                    }
                }
            }

            if (isset($config['layoutdir'])) {
                $dirs = (array) $config['layoutdir'];
                array_walk(
                    $dirs,
                    ['\BackBee\Utils\File\File', 'resolveFilepath'],
                    ['base_dir' => $this->getApplication()->getRepository()]
                );

                foreach ($dirs as $dir) {
                    if (file_exists($dir) && is_dir($dir)) {
                        $this->_layoutdir[] = $dir;
                    }
                }

                if ($this->getApplication()->hasContext()) {
                    $dirs = (array) $config['layoutdir'];
                    array_walk(
                        $dirs,
                        ['\BackBee\Utils\File\File', 'resolveFilepath'],
                        ['base_dir' => $this->getApplication()->getBaseRepository()]
                    );

                    foreach ($dirs as $dir) {
                        if (file_exists($dir) && is_dir($dir)) {
                            $this->_layoutdir[] = $dir;
                        }
                    }
                }
            }
        }

        if (isset($rendererConfig['bb_scripts_directory'])) {
            $directories = (array) $rendererConfig['bb_scripts_directory'];
            foreach ($directories as $directory) {
                if (is_dir($directory) && is_readable($directory)) {
                    $this->_scriptdir[] = $directory;
                }
            }
        }

        $this->helpers = new ParameterBag();
    }

    public function __call($method, $argv)
    {
        if ('getRenderer' === $method) {
            return $this;
        }

        $helper = $this->getHelper($method);
        if (null === $helper) {
            $helper = $this->createHelper($method, $argv);
        }

        $helper->setRenderer($this);

        if (is_callable($helper)) {
            return call_user_func_array($helper, $argv);
        }

        return $helper;
    }

    /**
     * Magic method to get an assign var.
     *
     * @param string $var the name of the variable
     *
     * @return mixed the value
     */
    public function __get($var)
    {
        return isset($this->_vars[$var]) ? $this->_vars[$var] : null;
    }

    /**
     * Magic method to test the setting of an assign var.
     *
     * @codeCoverageIgnore
     *
     * @param string $var the name of the variable
     *
     * @return boolean
     */
    public function __isset($var)
    {
        return isset($this->_vars[$var]);
    }

    /**
     * Magic method to assign a var.
     *
     * @codeCoverageIgnore
     * @param  string    $var   the name of the variable
     * @param  mixed     $value the value of the variable
     * @return AbstractRenderer the current renderer
     */
    public function __set($var, $value = null)
    {
        $this->_vars[$var] = $value;

        return $this;
    }

    /**
     * Magic method to unset an assign var.
     *
     * @param string $var the name of the variable
     */
    public function __unset($var)
    {
        if (isset($this->_vars[$var])) {
            unset($this->_vars[$var]);
        }
    }

    public function __($string)
    {
        return $this->translate($string);
    }

    public function __clone()
    {
        $this
            ->cache()
            ->reset()
            ->updateHelpers()
        ;
    }

    /**
     * Add new helper directory in the choosen position.
     *
     * @codeCoverageIgnore
     *
     * @param string  $new_dir  location of the new directory
     * @param integer $position position in the array
     */
    public function addHelperDir($dir)
    {
        if (file_exists($dir) && is_dir($dir)) {
            $this->getApplication()->getAutoloader()->registerNamespace('BackBee\Renderer\Helper', $dir);
        }

        return $this;
    }

    /**
     * Add new layout directory in the choosen position.
     *
     * @codeCoverageIgnore
     *
     * @param string  $new_dir  location of the new directory
     * @param integer $position position in the array
     */
    public function addLayoutDir($new_dir, $position = 0)
    {
        if (file_exists($new_dir) && is_dir($new_dir)) {
            $this->insertInArrayOnPostion($this->_layoutdir, $new_dir, $position);
        }

        return $this;
    }

    /**
     * Add new script directory in the choosen position.
     *
     * @codeCoverageIgnore
     *
     * @param strimg  $new_dir  location of the new directory
     * @param integer $position position in the array
     */
    public function addScriptDir($new_dir, $position = 0)
    {
        if (file_exists($new_dir) && is_dir($new_dir)) {
            $this->insertInArrayOnPostion($this->_scriptdir, $new_dir, $position);
        }

        return $this;
    }

    public function overload($new_dir)
    {
        array_unshift($this->_scriptdir, $new_dir);
        $this->__overloaded = $this->__overloaded + 1;
    }

    public function release()
    {
        for ($i = 0; $i < $this->__overloaded; $i++) {
            unset($this->_scriptdir[$i]);
        }
        $this->_scriptdir = array_values($this->_scriptdir);
        $this->__overloaded = 0;
    }

    /**
     * Returns an array of template files according the provided pattern.
     *
     * @param string $pattern
     *
     * @return array
     */
    public function getTemplatesByPattern($pattern)
    {
        File::resolveFilepath($pattern);

        $templates = [];
        foreach ($this->_scriptdir as $dir) {
            if (is_array(glob($dir.DIRECTORY_SEPARATOR.$pattern))) {
                $templates = array_merge($templates, glob($dir.DIRECTORY_SEPARATOR.$pattern));
            }
        }

        return $templates;
    }

    /**
     * Return the current token.
     *
     * @codeCoverageIgnore
     *
     * @return \Symfony\Component\Security\Core\Authentication\Token\AbstractToken
     */
    public function getToken()
    {
        return $this->getApplication()->getSecurityContext()->getToken();
    }

    /**
     * Returns the list of available render mode for the provided object.
     *
     * @param  \BackBee\Renderer\RenderableInterface $object
     * @return array
     */
    public function getAvailableRenderMode(RenderableInterface $object)
    {
        $templatePath = $this->getTemplatePath($object);
        $templates = $this->getTemplatesByPattern($templatePath.'.*');
        $modes = [];
        foreach ($templates as $template) {
            $modes[] = basename(str_replace($templatePath.'.', '', $template));
        }

        return $modes;
    }

    /**
     * Returns the ignore state of rendering if render mode is not available.
     *
     * @return Boolean
     * @codeCoverageIgnore
     */
    public function getIgnoreIfNotAvailable()
    {
        return $this->_ignoreIfRenderModeNotAvailable;
    }

    /**
     * Assign one or more variables.
     *
     * @param mixed $var   A variable name or an array of variables to set
     * @param mixed $value The variable value to set
     *
     * @return AbstractRenderer The current renderer
     */
    public function assign($var, $value = null)
    {
        if (is_string($var)) {
            $this->_vars[$var] = $value;

            return $this;
        }

        if (is_array($var)) {
            foreach ($var as $key => $value) {
                if ($value instanceof AbstractClassContent) {
                    // trying to load subcontent
                    $subcontent = $this
                        ->getApplication()
                        ->getEntityManager()
                        ->getRepository(ClassUtils::getRealClass($value))
                        ->load($value, $this->getApplication()->getBBUserToken())
                    ;
                    if (null !== $subcontent) {
                        $value = $subcontent;
                    }
                }

                $this->_vars[$key] = $value;
            }
        }

        return $this;
    }

    /**
     * @codeCoverageIgnore
     *
     * @return \BackBee\BBApplication
     */
    public function getApplication()
    {
        return $this->application;
    }

    /**
     * Return the assigned variables.
     *
     * @codeCoverageIgnore
     *
     * @return array Array of assigned variables
     */
    public function getAssignedVars()
    {
        return $this->_vars;
    }

    /**
     * @codeCoverageIgnore
     *
     * @return type
     */
    public function getClassContainer()
    {
        return $this->__object;
    }

    /**
     * @codeCoverageIgnore
     *
     * @return type
     */
    public function getCurrentElement()
    {
        return $this->__currentelement;
    }

    /**
     * Returns $pathinfo with base url of current page
     * If $site is provided, the url will be pointing on the associate domain.
     *
     * @param string             $pathinfo
     * @param string             $defaultExt
     * @param \BackBee\Site\Site $site
     *
     * @return string
     */
    public function getUri($pathinfo = null, $defaultExt = null, Site $site = null, $url_type = null)
    {
        return $this->getApplication()->getRouting()->getUri($pathinfo, $defaultExt, $site, $url_type);
    }

    public function getRelativeUrl($uri)
    {
        $url = $uri;

        if ($this->application->isStarted()) {
            $request = $this->application->getRequest();
            $baseurl = str_replace('\\', '/', $request->getSchemeAndHttpHost().dirname($request->getBaseUrl()));
            $url = str_replace($baseurl, '', $uri);

            if (false !== $ext = strrpos($url, '.')) {
                $url = substr($url, 0, $ext);
            }

            if ('/' !== $url[0]) {
                $url = '/'.$url;
            }
        }

        return $url;
    }

    /**
     * @codeCoverageIgnore
     *
     * @return type
     */
    public function getMaxEntry()
    {
        return $this->_maxentry;
    }

    /**
     * Return the current rendering mode.
     *
     * @codeCoverageIgnore
     *
     * @return string
     */
    public function getMode()
    {
        return $this->_mode;
    }

    /**
     * @codeCoverageIgnore
     *
     * @return type
     */
    public function getNode()
    {
        return $this->_node;
    }

    /**
     * Return the object to be rendered.
     *
     * @codeCoverageIgnore
     * @return RenderableInterface
     */
    public function getObject()
    {
        return $this->_object;
    }

    /**
     * @codeCoverageIgnore
     *
     * @return type
     */
    public function getParentUid()
    {
        return $this->_parentuid;
    }

    /**
     * Return the previous object to be rendered.
     *
     * @codeCoverageIgnore
     * @return RenderableInterface or null
     */
    public function getPreviousObject()
    {
        return $this->__object;
    }

    /**
     * Return the current page to be rendered.
     *
     * @codeCoverageIgnore
     *
     * @return null|BackBee\NestedNode\Page
     */
    public function getCurrentPage()
    {
        return $this->_currentpage;
    }

    /**
     * Return the current root of the page to be rendered.
     *
     * @return null|BackBee\NestedNode\Page
     */
    public function getCurrentRoot()
    {
        if (null !== $this->getCurrentPage()) {
            return $this->getCurrentPage()->getRoot();
        } elseif (!$this->getCurrentSite()) {
            return;
        } else {
            return $this->application->getEntityManager()
                ->getRepository('BackBee\NestedNode\Page')
                ->getRoot($this->getCurrentSite())
            ;
        }
    }

    /**
     * return the current rendered site.
     *
     * @codeCoverageIgnore
     *
     * @return null|BackBee\Site\Site
     */
    public function getCurrentSite()
    {
        return $this->application->getSite();
    }

    /**
     * Return parameters.
     *
     * @param string $param The parameter to return
     *
     * @return mixed The parameter value asked or array of the parameters
     */
    public function getParam($param = null)
    {
        if (null === $param) {
            return $this->_params;
        }

        return isset($this->_params[$param]) ? $this->_params[$param] : null;
    }

    /**
     * Processes a view script and returns the output.
     *
     * @access public
     * @param  RenderableInterface $content                        The object to be rendered
     * @param  string      $mode                           The rendering mode
     * @param  array       $params                         A force set of parameters
     * @param  string      $template                       A force template script to be rendered
     * @param  Boolean     $ignoreIfRenderModeNotAvailable Ignore the rendering if specified render mode is not
     *                                                     available if TRUE, use the default template otherwise
     * @return string      The view script output
     */
    public function render(RenderableInterface $content = null, $mode = null, $params = null, $template = null, $ignoreIfRenderModeNotAvailable = true)
    {
        // Nothing to do
    }

    public function partial($template = null, $params = null)
    {
        // Nothing to do
    }

    /**
     * Render an error layout according to code.
     *
     * @param int    $error_code Error code
     * @param string $title      Optional error title
     * @param string $message    Optional error message
     * @param string $trace      Optional error trace
     *
     * @return boolean|string false if none layout found or the rendered layout
     */
    public function error($error_code, $title = null, $message = null, $trace = null)
    {
        return false;
    }

    public function reset()
    {
        $this
            ->resetVars()
            ->resetParams()
        ;

        $this->__render = null;

        return $this;
    }

    /**
     * Set the rendering mode.
     *
     * @codeCoverageIgnore
     *
     * @param string  $mode
     * @param Boolean $ignoreIfRenderModeNotAvailable Ignore the rendering if specified render mode is not
     *                                                available if TRUE, use the default template otherwise
     * @return AbstractRenderer The current renderer
     */
    public function setMode($mode = null, $ignoreIfRenderModeNotAvailable = true)
    {
        $this->_mode = false == $mode ? null : $mode;
        $this->_ignoreIfRenderModeNotAvailable = $ignoreIfRenderModeNotAvailable;

        return $this;
    }

    /**
     * @codeCoverageIgnore
     *
     * @param  Page $node
     * @return AbstractRenderer
     */
    public function setNode(Page $node)
    {
        $this->_node = $node;

        return $this;
    }

    /**
     * Set the object to render.
     *
     * @param  RenderableInterface $object
     * @return AbstractRenderer   The current renderer
     */
    public function setObject(RenderableInterface $object = null)
    {
        $this->__currentelement = null;
        $this->_object = $object;

        if (is_array($this->__vars) && 0 < count($this->__vars)) {
            foreach ($this->__vars as $key => $var) {
                if ($var === $object) {
                    $this->__currentelement = $key;
                }
            }
        }

        return $this;
    }

    /**
     * Set the current page.
     *
     * @param  Page $page
     *
     * @return AbstractRenderer
     */
    public function setCurrentPage(Page $page = null)
    {
        $this->_currentpage = $page;

        return $this;
    }

    /**
     * Set one or set of parameters.
     *
     * @param mixed $param A parameter name or an array of parameters to set
     * @param mixed $value The parameter value to set
     *
     * @return AbstractRenderer The current renderer
     */
    public function setParam($param, $value = null)
    {
        if (is_string($param)) {
            $this->_params[$param] = $value;

            return $this;
        }

        if (is_array($param)) {
            foreach ($param as $key => $value) {
                $this->_params[$key] = $value;
            }
        }

        return $this;
    }

    /**
     * @codeCoverageIgnore
     *
     * @param type $render
     *
     * @return AbstractRenderer
     */
    public function setRender($render)
    {
        $this->__render = $render;

        return $this;
    }

    /**
     * @return string
     */
    public function getRender()
    {
        return $this->__render;
    }

    /**
     * Updates a file script of a layout.
     *
     * @param Layout $layout The layout to update
     *
     * @return string The filename of the updated script
     */
    public function updateLayout(Layout $layout)
    {
        if (null === $layout->getSite()) {
            return false;
        }

        $layoutfile = $this->getLayoutFile($layout);
        File::resolveFilepath($layoutfile, null, array('include_path' => $this->_layoutdir));

        if (!file_exists($layoutfile)) {
            File::resolveFilepath($layoutfile, null, array('base_dir' => $this->_layoutdir[1]));
        }

        if (!file_exists($layoutfile) && !touch($layoutfile)) {
            throw new RendererException(
                sprintf('Unable to create file %s.', $layoutfile),
                RendererException::LAYOUT_ERROR
            );
        }

        if (!is_writable($layoutfile)) {
            throw new RendererException(
                sprintf('Unable to open file %s in writing mode.', $layoutfile),
                RendererException::LAYOUT_ERROR
            );
        }

        return $layoutfile;
    }

    /**
     * Unlink a file script of a layout.
     *
     * @param Layout $layout The layout to update
     */
    public function removeLayout(Layout $layout)
    {
        if (null === $layout->getSite()) {
            return false;
        }

        $layoutfile = $this->getLayoutFile($layout);
        @unlink($layoutfile);
    }

    /**
     * Returns helper if it exists or null.
     *
     * @param [type] $method
     *
     * @return AHelper|null
     */
    public function getHelper($method)
    {
        $helper = null;
        if ($this->helpers->has($method)) {
            $helper = $this->helpers->get($method);
        }

        return $helper;
    }

    /**
     * Create a new helper if class exists.
     *
     * @param string $method
     * @param array  $argv
     *
     * @return AHelper|null
     */
    public function createHelper($method, $argv)
    {
        $helper = null;
        $helperClass = 'BackBee\Renderer\Helper\\'.$method;
        if (class_exists($helperClass)) {
            $this->helpers->set($method, new $helperClass($this, $argv));
            $helper = $this->helpers->get($method);
        }

        return $helper;
    }

    protected function setCurrentElement($key)
    {
        $this->__currentelement = $key;
    }

    /**
     * Return the relative path from the classname of an object.
     *
     * @param  \BackBee\Renderer\RenderableInterface $object
     * @return string
     */
    protected function getTemplatePath(RenderableInterface $object)
    {
        return $object->getTemplateName();
    }

    protected function updateHelpers()
    {
        foreach ($this->helpers->all() as $h) {
            $h->setRenderer($this);
        }
    }

    /**
     * Add new entry in the choosen position.
     *
     * @param array   $array     Arry to modify
     * @param string  $new_value location of the new directory
     * @param integer $position  position in the array
     */
    protected function insertInArrayOnPostion(array &$array, $newValue, $position)
    {
        if (in_array($newValue, $array)) {
            foreach ($array as $key => $value) {
                if ($value == $newValue) {
                    unset($array[$key]);
                    $count = count($array);
                    for ($i = ($key + 1); $i < $count; $i++) {
                        $array[$i - 1] = $array[$i];
                    }

                    break;
                }
            }
        }
        if ($position <= 0) {
            $position = 0;
            array_unshift($array, $newValue);
        } elseif ($position >= count($array)) {
            $array[count($array) - 1] = $newValue;
        } else {
            for ($i = (count($array) - 1); $i >= $position; $i--) {
                $array[$i + 1] = $array[$i];
            }

            $array[$position] = $newValue;
        }

        ksort($array);
    }

    /**
     * Return the file path to current layout, try to create it if not exists.
     *
     * @param Layout $layout
     *
     * @return string the file path
     *
     * @throws RendererException
     */
    protected function getLayoutFile(Layout $layout)
    {
        $layoutfile = $layout->getPath();
        if (null === $layoutfile && 0 < count($this->_includeExtensions)) {
            $ext = reset($this->_includeExtensions);
            $layoutfile = StringUtils::toPath($layout->getLabel(), array('extension' => $ext));
            $layout->setPath($layoutfile);
        }

        return $layoutfile;
    }

    protected function triggerEvent($name = 'render', $object = null, $render = null)
    {
        $dispatcher = $this->application->getEventDispatcher();
        $object = null !== $object ? $object : $this->getObject();
        $event = new RendererEvent($object, null === $render ? $this : [$this, $render]);
        $dispatcher->triggerEvent($name, $object, null, $event);
    }

    protected function cache()
    {
        if (null !== $this->_object) {
            $this->_parentuid = $this->_object->getUid();
            $this->__object = $this->_object;
        }

        $this->__vars = $this->_vars;

        return $this;
    }

    /**
     * @codeCoverageIgnore
     *
     * @return AbstractRenderer
     */
    private function resetVars()
    {
        $this->_vars = [];

        return $this;
    }

    /**
     * @codeCoverageIgnore
     * @return AbstractRenderer
     */
    private function resetParams()
    {
        $this->_params = [];

        return $this;
    }
}
