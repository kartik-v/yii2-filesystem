<?php
/**
 * @package   yii2-filesystem
 * @author    Kartik Visweswaran <kartikv2@gmail.com>
 * @copyright Copyright &copy; Kartik Visweswaran, Krajee.com, 2018 - 2019
 * @version   1.0.0
 */

namespace kartik\filesystem;

use DirectoryIterator;
use Exception;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Yii;

/**
 * Folder structure browser, lists folders and files. Provides an object oriented interface for common directory
 * related tasks. This is a port of CakePHP FileSystem Folder class for Yii2.
 */
class Folder
{
    /**
     * Default temporary path
     */
    const TEMP_PATH = '@runtime/tmp';

    /**
     * Default scheme for [[Folder::copy]]. Recursively merges subfolders with the same name.
     */
    const MERGE = 'merge';

    /**
     * Overwrite scheme for [[Folder::copy]]. Subfolders with the same name will be replaced.
     */
    const OVERWRITE = 'overwrite';

    /**
     * Skip scheme for [[Folder::copy]]. If a subfolder with the same name exists it will be skipped.
     */
    const SKIP = 'skip';

    /**
     * Sort mode by name
     */
    const SORT_NAME = 'name';

    /**
     * Sort mode by time
     */
    const SORT_TIME = 'time';

    /**
     * @var string path to folder (can be set up using Yii path aliases)
     */
    public $path;

    /**
     * @var bool whether or not list results should be sorted by name.
     */
    public $sort = false;

    /**
     * @var int mode to be used on create (does nothing on windows platforms).
     */
    public $mode = 0755;

    /**
     * @var array functions to be called depending on the sort type chosen.
     */
    protected $_fsorts = [
        self::SORT_NAME => 'getPathname',
        self::SORT_TIME => 'getCTime',
    ];

    /**
     * @var array holds list of messages from last method
     */
    protected $_messages = [];

    /**
     * @var array holds list of errors from last method
     */
    protected $_errors = [];

    /**
     * @var array holds list of complete directory paths
     */
    protected $_directories;

    /**
     * @var array holds list of complete file paths.
     */
    protected $_files;

    /**
     * Folder constructor
     *
     * @param string|null $path   path to folder
     * @param bool        $create whether to create folder if not found
     * @param int|false   $mode   mode (CHMOD) to apply to created folder, `false` to ignore
     */
    public function __construct($path = null, $create = false, $mode = false)
    {
        $path = Yii::getAlias(empty($path) ? self::TEMP_PATH : $path);
        if ($mode) {
            $this->mode = $mode;
        }
        if (!file_exists($path) && $create === true) {
            $this->create($path, $this->mode);
        }
        if (!static::isAbsolute($path)) {
            $path = realpath($path);
        }
        if (!empty($path)) {
            $this->cd($path);
        }
    }

    /**
     * Returns true if given $path is a windows path.
     *
     * @param string $path Path to check
     *
     * @return bool whether windows path
     */
    public static function isWindowsPath($path)
    {
        return (preg_match('/^[A-Z]:\\\\/i', $path) || substr($path, 0, 2) === '\\\\');
    }

    /**
     * Whether given $path is an absolute path.
     *
     * @param string $path path to check
     *
     * @return bool whether path is absolute.
     */
    public static function isAbsolute($path)
    {
        if (empty($path)) {
            return false;
        }
        return $path[0] === '/' ||
            preg_match('/^[A-Z]:\\\\/i', $path) ||
            substr($path, 0, 2) === '\\\\' ||
            static::isRegisteredStreamWrapper($path);
    }

    /**
     * Whether given $path is a registered stream wrapper.
     *
     * @param string $path path to check
     *
     * @return bool whether path is registered stream wrapper.
     */
    public static function isRegisteredStreamWrapper($path)
    {
        return preg_match('/^[^:\/\/]+?(?=:\/\/)/', $path, $matches) && in_array($matches[0], stream_get_wrappers());
    }

    /**
     * Returns a correct set of slashes for given $path. (\\ for Windows paths and / for other paths.)
     *
     * @param string $path path to check
     *
     * @return string set of slashes ("\\" or "/")
     */
    public static function normalizePath($path)
    {
        return static::correctSlashFor($path);
    }

    /**
     * Returns a correct set of slashes for given $path. (\\ for Windows paths and / for other paths.)
     *
     * @param string $path path to check
     *
     * @return string set of slashes ("\\" or "/")
     */
    public static function correctSlashFor($path)
    {
        return static::isWindowsPath($path) ? '\\' : '/';
    }

    /**
     * Returns $path with added terminating slash (corrected for Windows or other OS).
     *
     * @param string $path path to check
     *
     * @return string path with ending slash
     */
    public static function slashTerm($path)
    {
        if (static::isSlashTerm($path)) {
            return $path;
        }
        return $path . static::correctSlashFor($path);
    }

    /**
     * Returns $path with $element added, with correct slash in-between.
     *
     * @param string       $path    path
     * @param string|array $element element to add at end of path
     *
     * @return string combined path
     */
    public static function addPathElement($path, $element)
    {
        $element = (array)$element;
        array_unshift($element, rtrim($path, DIRECTORY_SEPARATOR));
        return implode(DIRECTORY_SEPARATOR, $element);
    }

    /**
     * Whether given $path ends in a slash (i.e. is slash-terminated).
     *
     * @param string $path path to check
     *
     * @return bool whether path ends with slash
     */
    public static function isSlashTerm($path)
    {
        $lastChar = $path[strlen($path) - 1];
        return $lastChar === '/' || $lastChar === '\\';
    }

    /**
     * Return current path.
     *
     * @return string current path
     */
    public function pwd()
    {
        return $this->path;
    }

    /**
     * Change directory to $path.
     *
     * @param string $path path to the directory to change to
     *
     * @return string|bool the new path
     */
    public function cd($path)
    {
        $path = $this->realpath($path);
        if ($path !== false && is_dir($path)) {
            return $this->path = $path;
        }
        return false;
    }

    /**
     * List of the contents of the current directory. The returned array holds two arrays:
     * One of directories and one of files.
     *
     * @param string|bool $sort       whether you want the results sorted, set this and the sort property
     *                                to false to get unsorted results.
     * @param array|bool  $exceptions either an array or boolean true will not grab dot files
     * @param bool        $fullPath   whether to return the full path
     *
     * @return array contents of current directory as an array, an empty array on failure
     */
    public function read($sort = self::SORT_NAME, $exceptions = false, $fullPath = false)
    {
        $dirs = $files = [];
        if (!$this->pwd()) {
            return [$dirs, $files];
        }
        if (is_array($exceptions)) {
            $exceptions = array_flip($exceptions);
        }
        $skipHidden = isset($exceptions['.']) || $exceptions === true;
        try {
            $iterator = new DirectoryIterator($this->path);
        } catch (Exception $e) {
            return [$dirs, $files];
        }
        if (!is_bool($sort) && isset($this->_fsorts[$sort])) {
            $methodName = $this->_fsorts[$sort];
        } else {
            $methodName = $this->_fsorts[self::SORT_NAME];
        }
        foreach ($iterator as $item) {
            if ($item->isDot()) {
                continue;
            }
            $name = $item->getFilename();
            if ($skipHidden && $name[0] === '.' || isset($exceptions[$name])) {
                continue;
            }
            if ($fullPath) {
                $name = $item->getPathname();
            }
            if ($item->isDir()) {
                $dirs[$item->{$methodName}()][] = $name;
            } else {
                $files[$item->{$methodName}()][] = $name;
            }
        }
        if ($sort || $this->sort) {
            ksort($dirs);
            ksort($files);
        }
        if ($dirs) {
            $dirs = array_merge(...array_values($dirs));
        }
        if ($files) {
            $files = array_merge(...array_values($files));
        }
        return [$dirs, $files];
    }

    /**
     * List of all matching files in current directory.
     *
     * @param string $regexpPattern preg_match pattern (Defaults to: .*)
     * @param bool   $sort          whether results should be sorted.
     *
     * @return array list of files that match given pattern
     */
    public function find($regexpPattern = '.*', $sort = false)
    {
        list(, $files) = $this->read($sort);
        return array_values(preg_grep('/^' . $regexpPattern . '$/i', $files));
    }

    /**
     * Whether the folder is in the given path.
     *
     * @param string $path    the absolute path to check that the current `pwd()` resides within.
     * @param bool   $reverse reverse the search, check if the given `$path` resides within the current `pwd()`.
     *
     * @return bool whether folder is in given path
     *
     * @throws InvalidArgumentException when the given `$path` argument is not an absolute path.
     */
    public function inPath($path, $reverse = false)
    {
        if (!static::isAbsolute($path)) {
            throw new InvalidArgumentException('The $path argument is expected to be an absolute path.');
        }
        $dir = static::slashTerm($path);
        $current = static::slashTerm($this->pwd());
        if (!$reverse) {
            $return = preg_match('/^' . preg_quote($dir, '/') . '(.*)/', $current);
        } else {
            $return = preg_match('/^' . preg_quote($current, '/') . '(.*)/', $dir);
        }
        return (bool)$return;
    }

    /**
     * Change the mode on a directory structure recursively. This includes changing the mode on files as well.
     *
     * @param string   $path       The path to chmod.
     * @param int|bool $mode       Octal value, e.g. 0755.
     * @param bool     $recursive  Chmod recursively, set to false to only change the current directory.
     * @param array    $exceptions Array of files, directories to skip.
     *
     * @return bool whether the operation was successful
     */
    public function chmod($path, $mode = false, $recursive = true, array $exceptions = [])
    {
        if (!$mode) {
            $mode = $this->mode;
        }
        if ($recursive === false && is_dir($path)) {
            if (@chmod($path, intval($mode, 8))) {
                $this->_messages[] = sprintf('%s changed to %s', $path, $mode);
                return true;
            }
            $this->_errors[] = sprintf('%s NOT changed to %s', $path, $mode);
            return false;
        }
        if (is_dir($path)) {
            $paths = $this->tree($path);
            foreach ($paths as $type) {
                foreach ($type as $fullpath) {
                    $check = explode(DIRECTORY_SEPARATOR, $fullpath);
                    $count = count($check);
                    if (in_array($check[$count - 1], $exceptions)) {
                        continue;
                    }
                    if (@chmod($fullpath, intval($mode, 8))) {
                        $this->_messages[] = sprintf('%s changed to %s', $fullpath, $mode);
                    } else {
                        $this->_errors[] = sprintf('%s NOT changed to %s', $fullpath, $mode);
                    }
                }
            }
            if (empty($this->_errors)) {
                return true;
            }
        }
        return false;
    }

    /**
     * List of subdirectories for the provided or current path.
     *
     * @param string|null $path     The directory path to get subdirectories for.
     * @param bool        $fullPath Whether to return the full path or only the directory name.
     *
     * @return array list of subdirectories for the provided or current path.
     */
    public function subdirectories($path = null, $fullPath = true)
    {
        if (!$path) {
            $path = $this->path;
        }
        $subdirectories = [];
        try {
            $iterator = new DirectoryIterator($path);
        } catch (Exception $e) {
            return [];
        }
        foreach ($iterator as $item) {
            if (!$item->isDir() || $item->isDot()) {
                continue;
            }
            $subdirectories[] = $fullPath ? $item->getRealPath() : $item->getFilename();
        }
        return $subdirectories;
    }

    /**
     * List of nested directories and files in each directory
     *
     * @param string|null $path       the directory path to build the tree from
     * @param array|bool  $exceptions Either an array of files/folder to exclude or boolean true to not grab dot
     *                                files/folders
     * @param string|null $type       either 'file' or 'dir'. Null returns both files and directories
     *
     * @return array list of nested directories and files in each directory
     */
    public function tree($path = null, $exceptions = false, $type = null)
    {
        if (!$path) {
            $path = $this->path;
        }
        $files = [];
        $directories = [$path];
        if (is_array($exceptions)) {
            $exceptions = array_flip($exceptions);
        }
        $skipHidden = false;
        if ($exceptions === true) {
            $skipHidden = true;
        } elseif (isset($exceptions['.'])) {
            $skipHidden = true;
            unset($exceptions['.']);
        }
        try {
            $directory = new RecursiveDirectoryIterator(
                $path,
                RecursiveDirectoryIterator::KEY_AS_PATHNAME | RecursiveDirectoryIterator::CURRENT_AS_SELF
            );
            $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::SELF_FIRST);
        } catch (Exception $e) {
            if ($type === null) {
                return [[], []];
            }
            return [];
        }
        /**
         * @var DirectoryIterator $item
         */
        foreach ($iterator as $itemPath => $fsIterator) {
            if ($skipHidden) {
                $subPathName = $fsIterator->getSubPathname();
                if ($subPathName{0} === '.' || strpos($subPathName, DIRECTORY_SEPARATOR . '.') !== false) {
                    continue;
                }
            }
            $item = $fsIterator->current();
            if (!empty($exceptions) && isset($exceptions[$item->getFilename()])) {
                continue;
            }
            if ($item->isFile()) {
                $files[] = $itemPath;
            } elseif ($item->isDir() && !$item->isDot()) {
                $directories[] = $itemPath;
            }
        }
        if ($type === null) {
            return [$directories, $files];
        }
        if ($type === 'dir') {
            return $directories;
        }
        return $files;
    }

    /**
     * Create a directory structure recursively.
     *
     * Can be used to create deep path structures like `/foo/bar/baz/shoe/horn`
     *
     * @param string   $pathname the directory structure to create. Either an absolute or relative path. If the path is
     *                           relative and exists in the process' cwd it will not be created. Otherwise relative
     *                           paths will be prefixed with the current pwd().
     * @param int|bool $mode     octal value 0755
     *
     * @return bool returns `TRUE` on success, `FALSE` on failure
     */
    public function create($pathname, $mode = false)
    {
        if (is_dir($pathname) || empty($pathname)) {
            return true;
        }
        if (!static::isAbsolute($pathname)) {
            $pathname = static::addPathElement($this->pwd(), $pathname);
        }
        if (!$mode) {
            $mode = $this->mode;
        }
        if (is_file($pathname)) {
            $this->_errors[] = sprintf('%s is a file', $pathname);

            return false;
        }
        $pathname = rtrim($pathname, DIRECTORY_SEPARATOR);
        $nextPathname = substr($pathname, 0, strrpos($pathname, DIRECTORY_SEPARATOR));
        if ($this->create($nextPathname, $mode)) {
            if (!file_exists($pathname)) {
                $old = umask(0);
                if (mkdir($pathname, $mode, true)) {
                    umask($old);
                    $this->_messages[] = sprintf('%s created', $pathname);

                    return true;
                }
                umask($old);
                $this->_errors[] = sprintf('%s NOT created', $pathname);

                return false;
            }
        }
        return false;
    }

    /**
     * Returns the size in bytes of this Folder and its contents.
     *
     * @return int size in bytes of current folder
     */
    public function dirsize()
    {
        $size = 0;
        $directory = static::slashTerm($this->path);
        $stack = [$directory];
        $count = count($stack);
        for ($i = 0, $j = $count; $i < $j; $i++) {
            if (is_file($stack[$i])) {
                $size += filesize($stack[$i]);
            } elseif (is_dir($stack[$i])) {
                $dir = dir($stack[$i]);
                if ($dir) {
                    while (($entry = $dir->read()) !== false) {
                        if ($entry === '.' || $entry === '..') {
                            continue;
                        }
                        $add = $stack[$i] . $entry;

                        if (is_dir($stack[$i] . $entry)) {
                            $add = static::slashTerm($add);
                        }
                        $stack[] = $add;
                    }
                    $dir->close();
                }
            }
            $j = count($stack);
        }
        return $size;
    }

    /**
     * Recursively remove directories if the system allows.
     *
     * @param string|null $path path of directory to delete
     *
     * @return bool whether the operation was successful
     */
    public function delete($path = null)
    {
        if (!$path) {
            $path = $this->pwd();
        }
        if (!$path) {
            return false;
        }
        $path = static::slashTerm($path);
        if (is_dir($path)) {
            try {
                $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::CURRENT_AS_SELF);
                $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::CHILD_FIRST);
            } catch (Exception $e) {
                return false;
            }

            foreach ($iterator as $item) {
                $filePath = $item->getPathname();
                if ($item->isFile() || $item->isLink()) {
                    //@codingStandardsIgnoreStart
                    if (@unlink($filePath)) {
                        //@codingStandardsIgnoreEnd
                        $this->_messages[] = sprintf('%s removed', $filePath);
                    } else {
                        $this->_errors[] = sprintf('%s NOT removed', $filePath);
                    }
                } elseif ($item->isDir() && !$item->isDot()) {
                    //@codingStandardsIgnoreStart
                    if (@rmdir($filePath)) {
                        //@codingStandardsIgnoreEnd
                        $this->_messages[] = sprintf('%s removed', $filePath);
                    } else {
                        $this->_errors[] = sprintf('%s NOT removed', $filePath);

                        return false;
                    }
                }
            }
            $path = rtrim($path, DIRECTORY_SEPARATOR);
            if (@rmdir($path)) {
                $this->_messages[] = sprintf('%s removed', $path);
            } else {
                $this->_errors[] = sprintf('%s NOT removed', $path);
                return false;
            }
        }
        return true;
    }

    /**
     * Recursive directory copy
     *
     * @param array|string $options Either an array of options (see above) or a string of the destination directory.
     *                              The following options are supported:
     *                              - `to`: _string_, the directory to copy to.
     *                              - `from`: _string_, the directory to copy from, this will cause a cd() to occur,
     *                              changing the results of pwd().
     *                              - `mode`: _int_, the mode to copy the files/directories, e.g. 0775.
     *                              - `skip`: _string_, files/directories to skip.
     *                              - `scheme`: _string_, one of self::MERGE, self::OVERWRITE, self::SKIP
     *                              - `recursive`: _bool_, whether to copy recursively or not (default: true -
     *                              recursive)
     *
     *
     * @return bool whether the operation was successful
     */
    public function copy($options)
    {
        if (!$this->pwd()) {
            return false;
        }
        $to = null;
        if (is_string($options)) {
            $to = $options;
            $options = [];
        }
        $options += [
            'to' => $to,
            'from' => $this->path,
            'mode' => $this->mode,
            'skip' => [],
            'scheme' => self::MERGE,
            'recursive' => true,
        ];
        $fromDir = $options['from'];
        $toDir = $options['to'];
        $mode = $options['mode'];
        if (!$this->cd($fromDir)) {
            $this->_errors[] = sprintf('%s not found', $fromDir);

            return false;
        }
        if (!is_dir($toDir)) {
            $this->create($toDir, $mode);
        }
        if (!is_writable($toDir)) {
            $this->_errors[] = sprintf('%s not writable', $toDir);
            return false;
        }
        $exceptions = array_merge(['.', '..', '.svn'], $options['skip']);
        if ($handle = @opendir($fromDir)) {
            while (($item = readdir($handle)) !== false) {
                $to = static::addPathElement($toDir, $item);
                if (($options['scheme'] != self::SKIP || !is_dir($to)) && !in_array($item, $exceptions)) {
                    $from = static::addPathElement($fromDir, $item);
                    if (is_file($from) && (!is_file($to) || $options['scheme'] != self::SKIP)) {
                        if (copy($from, $to)) {
                            chmod($to, intval($mode, 8));
                            touch($to, filemtime($from));
                            $this->_messages[] = sprintf('%s copied to %s', $from, $to);
                        } else {
                            $this->_errors[] = sprintf('%s NOT copied to %s', $from, $to);
                        }
                    }
                    if (is_dir($from) && file_exists($to) && $options['scheme'] === self::OVERWRITE) {
                        $this->delete($to);
                    }
                    if (is_dir($from) && $options['recursive'] === false) {
                        continue;
                    }
                    if (is_dir($from) && !file_exists($to)) {
                        $old = umask(0);
                        if (mkdir($to, $mode, true)) {
                            umask($old);
                            $old = umask(0);
                            chmod($to, $mode);
                            umask($old);
                            $this->_messages[] = sprintf('%s created', $to);
                            $options = ['to' => $to, 'from' => $from] + $options;
                            $this->copy($options);
                        } else {
                            $this->_errors[] = sprintf('%s not created', $to);
                        }
                    } elseif (is_dir($from) && $options['scheme'] === self::MERGE) {
                        $options = ['to' => $to, 'from' => $from] + $options;
                        $this->copy($options);
                    }
                }
            }
            closedir($handle);
        } else {
            return false;
        }
        return empty($this->_errors);
    }

    /**
     * Recursive directory move.
     *
     * @param array|string $options (to, from, chmod, skip, scheme). The following options are supported:
     *                              - `to`: _string_, the directory to move to.
     *                              - `from`: _string_, the directory to move from, this will cause a cd() to occur,
     *                              changing the results of pwd().
     *                              - `mode`: _int_, the mode to move the files/directories, e.g. 0775.
     *                              - `skip`: _string_, files/directories to skip.
     *                              - `scheme`: _string_, one of self::MERGE, self::OVERWRITE, self::SKIP
     *                              - `recursive`: _bool_, whether to copy recursively or not (default: true -
     *                              recursive)
     *
     * @return bool whether the operation was successful
     */
    public function move($options)
    {
        $to = null;
        if (is_string($options)) {
            $to = $options;
            $options = (array)$options;
        }
        $options += ['to' => $to, 'from' => $this->path, 'mode' => $this->mode, 'skip' => [], 'recursive' => true];
        if ($this->copy($options) && $this->delete($options['from'])) {
            return (bool)$this->cd($options['to']);
        }
        return false;
    }

    /**
     * Get messages from latest method
     *
     * @param bool $reset whether to reset message stack after reading
     *
     * @return array
     */
    public function messages($reset = true)
    {
        $messages = $this->_messages;
        if ($reset) {
            $this->_messages = [];
        }
        return $messages;
    }

    /**
     * Get errors from latest method
     *
     * @param bool $reset whether to reset error stack after reading
     *
     * @return array
     */
    public function errors($reset = true)
    {
        $errors = $this->_errors;
        if ($reset) {
            $this->_errors = [];
        }
        return $errors;
    }

    /**
     * Get the real path (taking ".." and such into account)
     *
     * @param string $path path to resolve
     *
     * @return string|false the resolved path
     */
    public function realpath($path)
    {
        if (strpos($path, '..') === false) {
            if (!static::isAbsolute($path)) {
                $path = static::addPathElement($this->path, $path);
            }
            return $path;
        }
        $path = str_replace('/', DIRECTORY_SEPARATOR, trim($path));
        $parts = explode(DIRECTORY_SEPARATOR, $path);
        $newParts = [];
        $newPath = '';
        if ($path[0] === DIRECTORY_SEPARATOR) {
            $newPath = DIRECTORY_SEPARATOR;
        }
        while (($part = array_shift($parts)) !== null) {
            if ($part === '.' || $part === '') {
                continue;
            }
            if ($part === '..') {
                if (!empty($newParts)) {
                    array_pop($newParts);
                    continue;
                }
                return false;
            }
            $newParts[] = $part;
        }
        $newPath .= implode(DIRECTORY_SEPARATOR, $newParts);
        return static::slashTerm($newPath);
    }

    /**
     * List of all matching files in and below current directory.
     *
     * @param string $pattern preg_match pattern (Defaults to: .*)
     * @param bool   $sort    whether results should be sorted.
     *
     * @return array files matching $pattern
     */
    public function findRecursive($pattern = '.*', $sort = false)
    {
        if (!$this->pwd()) {
            return [];
        }
        $startsOn = $this->path;
        $out = $this->findRecursiveByPattern($pattern, $sort);
        $this->cd($startsOn);
        return $out;
    }

    /**
     * Protected function to find by pattern for [[findRecursive]] .
     *
     * @param string $pattern pattern to match against
     * @param bool   $sort    whether results should be sorted.
     *
     * @return array files matching pattern
     */
    protected function findRecursiveByPattern($pattern, $sort = false)
    {
        list($dirs, $files) = $this->read($sort);
        $found = [];
        foreach ($files as $file) {
            if (preg_match('/^' . $pattern . '$/i', $file)) {
                $found[] = static::addPathElement($this->path, $file);
            }
        }
        $start = $this->path;
        foreach ($dirs as $dir) {
            $this->cd(static::addPathElement($start, $dir));
            $found = array_merge($found, $this->findRecursive($pattern, $sort));
        }
        return $found;
    }
}
