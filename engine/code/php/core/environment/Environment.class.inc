<?php
define("DIR_INACTIVE", '_');
define("DIR_ALL", 'all');
define("DIR_DIRS", 'dirs');
define("DIR_FILES", 'files');
define("DIR_MODULES", 'modules');
define("GLOBAL_SEPARATOR", '@\@/@\@/@\@/@');

/**
 * Class Environment
 * This class represents an Environment with his directories etcetera.
 */
class Environment extends DataContainer
{
    public $host = array();
    public $dir = array();
    public $request_uri;
    public $request_json;
    public $request = array();
    private $modules = array();
    private $includes = array();
    private $items = array();
    private $context;

    // Construct the Environment.
    public function __construct($host = NULL, $request_uri = NULL)
    {
        $this->host = (($host == NULL) ? strtolower($_SERVER['HTTP_HOST']) : $host);
        if ($request_uri == NULL) {
            // Remove querystring to obtain request uri...
            $exp = explode('?', $_SERVER['REQUEST_URI']);
            $this->request_uri = (str_replace('/', '', $exp[0]) == '') ? 'home/' : $exp[0];
        } else {
            $this->request_uri = $request_uri;
        }
        $this->request = explode('/', $this->request_uri);
        $this->request_path = $this->request[count($this->request) - 2];
        $this->dir['docroot'] = $_SERVER['DOCUMENT_ROOT'] . '/sites/' . $this->host;
        $this->dir['tmp'] = $_SERVER['DOCUMENT_ROOT'] . '/engine/tmp/' . $this->host;
        $this->dir['modules_core'] = $_SERVER['DOCUMENT_ROOT'] . '/engine/code/php/core';
        $this->dir['modules_custom'] = $this->dir['docroot'] . '/_modules';
        // TODO: move to files module.
        $this->dir['tmp_files'] = $this->dir['tmp'] . '/files';
        $this->dir['log'] = $_SERVER['DOCUMENT_ROOT'] . '/engine/tmp/' . $this->host . '/log';
        // TODO: move to user module.
        $this->dir['users'] = $this->dir['docroot'] . '/_users';
        $this->dir['roles'] = $this->dir['docroot'] . '/_roles';

      if (isset($_REQUEST['json'])) {
            $this->request_json = json_decode($_REQUEST['json']);
        }
        $this->load();
    }


    /**
     * Set the content for the shadow.
     * @param $context
     */
    public function setContext($context)
    {
        $this->context = $context;
    }

    /**
     * Get the context of the shadow.
     * @return mixed
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * Get the context of the shadow.
     * @return mixed
     */
    public function getBaseUrl()
    {
        return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] && !in_array(strtolower($_SERVER['HTTPS']), array('off', 'no')) ? 'https' : 'http') . '://' . $this->host;
    }

    /**
     * Returns the current requested path.
     * @return string mixed
     */
    public function getRequestedPath()
    {
        return $this->request_path;
    }

    /**
     * Load core modules.
     * TODO: right now loaded in alphabetical order. This is not correct.
     */
    private function loadCoreModules()
    {
        $core_modules = $this->scanDirectory('core', array('type' => DIR_MODULES));
        $this->setModules('core', $core_modules);
    }

    private function loadContribModules()
    {
        $contrib_modules = $this->scanDirectory($this->dir['docroot'] . '/_modules', array('type' => DIR_MODULES));
        $this->setModules('contrib', $contrib_modules);
    }

    /**
     * Setup modules
     * @param $modules
     */
    public function setModules($mod_type, $modules)
    {
        $module_path = $mod_type == 'core' ? $this->dir('modules_core') : $this->dir('modules_custom');

        foreach ($modules as $k => $module) {
            $this->modules[$module] = array(
                'name' => $module,
                'path' => $module_path . '/' . $module,
            );
        }
    }

    public function getModule($module)
    {
        return $this->modules[$module];
    }

    public function getModules()
    {
        return $this->modules;
    }

    public function runModules()
    {
        foreach ($this->modules as $module) {
            $this->runModule($module);
        }
    }

    public function dependsFrom($mod_type, $module)
    {
        $this->runModule($this->getModule($module));
    }

    public function runModule($module)
    {
        $includes = array('api', 'hook', 'wiki');
        $module_path = $module['path'] . '/' . $module['name'] . '.module';
        if (is_file($module_path)) {
            require_once($module_path);
        }

        foreach ($includes as $include_type) {
            $include_path = $module['path'] . '/' . $module['name'] . '.' . $include_type . '.inc';
            if (is_file($include_path)) {
                require_once($include_path);
            }
        }
    }

    /**
     * Get all dirs inside a given dir.
     */
    public function scanDirectory($base_dir = '', $attributes = array())
    {
        if (!is_dir($base_dir)) {
            return array();
        }
        if (!isset($attributes['exclude_dirs'])) {
            $attributes['exclude_dirs'] = DIR_INACTIVE;
        }
        if (!isset($attributes['type'])) {
            $attributes['type'] = DIR_ALL;
        }

        $dirs = array_diff(scandir($base_dir), array('.', '..'));
        foreach ($dirs as $k => $dir) {
            // Remove inactive if requested.
            if (substr($dir, 0, 1) == $attributes['exclude_dirs']) {
                unset ($dirs[$k]);
            }

            if (isset($attributes['symlinks']) && $attributes['symlinks'] == 'no' && is_link($base_dir . '/' . $dir)) {
                unset ($dirs[$k]);
            } elseif (isset($attributes['symlinks']) && $attributes['symlinks'] == 'only' && !is_link($base_dir . '/' . $dir)) {
                unset ($dirs[$k]);
            }


            if ($attributes['type'] == DIR_DIRS && !is_dir($base_dir . '/' . $dir)) {
                unset ($dirs[$k]);
            } elseif ($attributes['type'] == DIR_FILES && !is_file($base_dir . '/' . $dir)) {
                unset ($dirs[$k]);
            } elseif ($attributes['type'] == DIR_MODULES &&
                (!is_dir($base_dir . '/' . $dir) || !is_file($base_dir . '/' . $dir . '/' . $dir . '.module'))
            ) {
                unset ($dirs[$k]);
            }
        }

        return $dirs;
    }


    /**
     * Get all dirs inside a given dir, at a leaf level.
     */
    public function scanDirectoryDeep($base_dir, $dir, $dirs = array(), $attributes = array('exclude_dirs' => DIR_INACTIVE, 'type' => DIR_ALL, 'level' => 'leaf'), $depth = 0)
    {
        $scan = ($this->scanDirectory($base_dir . '/' . $dir, $attributes));

        $item = array(
            'path' => $base_dir . '/' . $dir,
            'name' => $dir,
            'depth' => $depth,
        );
        if (count($scan) == 0 || ($depth != 0 && is_link($base_dir))) {
            $dirs[] = $item;
        } else {
            $i = 0;
            if ($attributes['level'] == 'tree') {
                $dirs[] = $item;
            }
            $depth++;
            foreach ($scan as $scandir) {

                $next_dir = ($base_dir . '/' . $dir . '/' . $scandir);
                if (is_dir($next_dir)) {
                    $dirs = $this->scanDirectoryDeep($base_dir . '/' . $dir, $scandir, $dirs, $attributes, $depth);
                    $i++;
                }
            }
            // If $i didn't grow, means the directory contains only files, so it's a leaf.
            if ($i == 0 && $attributes['level'] == 'leaf') {
                $dirs[] = $item;
            }
        }
        return $dirs;
    }

    // Load all system directories. Create those that are not created yet.
    private function load()
    {
        foreach ($this->dir as $folder) {
            $this->sysdir($folder);
        }
        $this->loadCoreModules();
        $this->loadContribModules();
    }

    // Return a system directory.
    public function dir($variable)
    {
        return $this->dir[$variable];
    }

    /**
     * Hook function - will look for all modulename_function in all active modules.
     *
     * @param $function
     * @param array $vars
     * @return bool
     */
    public function hook($function, $vars = array())
    {
        $vars['env'] = &$this;
        foreach ($this->getModules() as $module) {
            $hook = $module['name'] . '_' . $function;
            if (function_exists($hook)) {
                $hook($vars);
            }
        }
        return TRUE;
    }


    /**
     * Start the PHP session with the client.
     */
    public function startSession()
    {
        session_start();
    }

    /**
     * Get all included CSS / JS files.
     * @return array
     */
    public function getIncludes()
    {
        return $this->includes;
    }

    /**
     * Add a CSS / JS file to include.
     * @param $include
     * @param null $type
     */
    public function addInclude($include, $type = NULL)
    {
        if ($type == NULL) {
            $ext = explode('.', $include);
            $type = $ext[count($ext) - 1];
        }
        $this->includes[] = array('path' => $include, 'type' => $type);
    }

    /**
     * Check if there are any queued actions in the requeqst.
     */
    public function checkActions()
    {
        if (isset($this->request_json->action)) {

            $this->request_json->action = array_pop($this->request_json->action);

            $this->hook('action_' . $this->request_json->action, array('data' => (array) $this->request_json));
        }
    }

    /**
     * Adds an item to the environment. An item can be everything
     * (messages, nodes, ...)
     * @param $item
     */
    public function addItem($item, $module)
    {
        if (!is_object($item)) {
            die('Error: ' . var_export($item, 1) . ' is not a valid engine object');
        }
        $this->items[strtolower($module)][] = $item;
    }

    /**
     * Get all items of one type / module (i.e. message)
     * @param $module
     * @return object
     */
    public function getItems($module)
    {
        if (isset($this->items[strtolower($module)])) {
            $items = $this->items[strtolower($module)];
        } else {
            $items = array();
        }
        return $items;
    }

    /**
     * Verifies the path and / or creates a candidate path.
     * @param $title
     * @return string
     */
    public function getCandidatePath($title)
    {
        $candidate_path = strtolower(str_replace('_', '-', preg_replace('/[\s\W]+/', '', str_replace(' ', '_', trim($title)))));
        $i = 0;
        while (TRUE) {
            $node = new Node($this, $candidate_path);
            if (!$node->exists) {
                $path = $candidate_path;
                break;
            } else {
                $i++;
                $candidate_path = $candidate_path . '-' . $i;
            }
        }
        return $path;
    }

    /**
     * Creates a system directory (if it doesn't exist yet).
     * @param $folder
     * @return mixed
     */
    public function sysdir($folder)
    {
        if (!is_dir($folder) && !is_link($folder)) {
            mkdir($folder) or die("Error: can not create " . $folder . '. Check permission and path.');
        }
        return $folder;
    }

    public function nodePath($folder)
    {
        // Use find to locate the node's directory in the file system.
        // TODO: run a sanity check that there is only one folder or throw error instead?
        $findcmd = 'find ' . $this->dir['docroot'] . '/ -name "' . $folder . '"';

        // TODO: sometimes getting empty folder. Why? Temporary fix.
        if (empty($folder)) {
            return;
        }

        exec($findcmd, $results);
        $found_folders = array();

        // Check that there are not duplicate folders. Don't count symlinks.
        foreach ($results as $i => $res) {

            if (is_dir($results[$i]) && !is_link($results[$i])) {
                $found_folders[] = $results[$i];
                $result = $results[$i];

            } else {
                unset($results[$i]);
            }
        }

        if (count($found_folders) > 1) {
            new Message($this, 'Warning: there is more than one folder named ' . $folder . ': <br/>' . var_export($found_folders, 1) . '<br>Check integrity!');
        }
        elseif (empty($result) ) {
            //new Message($this, 'Warning: there is no folder named ' . $folder . '<br>Check integrity!');
        } else {
            return $result;
        }
    }
}
