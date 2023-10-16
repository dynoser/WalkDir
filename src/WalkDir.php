<?php
namespace dynoser\walkdir;

class WalkDir
{
    /**
     * Scan files and folders in $base_path and return Array of [ [N] => short_name (file OR folder)]
     * 
     * - folder elements contain DIRECTORY_SEPARATOR at the end
     * - files elements NOT contain DIRECTORY_SEPARATOR at the end
     * 
     * Not recursively! Returns items only from the 1st level of the specified folder.
     * 
     * The "glob" function is used internally.
     * 
     * You can specify one of the following parameters: $get_hidden=true OR $glob_mask
     * if $get_hidden=true then $glob_mask will generate automatically
     * if $get_hidden=false then you can set any $glob_mask ("*" by default)
     * 
     * @param string $basePath
     * @param bool $getHidden
     * @param string $globMask
     * @return array
     * @throws \Exception
     */
    public static function getNames(string $basePath, bool $getHidden = false, string $globMask = '*'): array {
        $realPath = \realpath($basePath);
        if (!$realPath) return [];
        $leftLen = \strlen($realPath) + 1;
        if ($getHidden) {
            if ($globMask !== '*') {
                throw new \Exception("get_hidden can use only with '*'-mask");
            }
            $globMask = '{,.}*';
        }
        if (!\is_dir($realPath)) {
            // It is one file only
            return [""];
        }
        // It is folder
        $arr = \glob($realPath . DIRECTORY_SEPARATOR . $globMask,  \GLOB_NOSORT | \GLOB_MARK | \GLOB_BRACE);
        if (!$arr) return [];
        $ret = [];
        foreach($arr as $fullName) {
            $ret[] = \substr($fullName, $leftLen);
        }
        return $ret;
    }
    /**
     * Scan files in $path recursively.
     *
     * Return: Array of file-full-names.
     *  All file-names in results-array have full-path separated by "/" (not "\")
     *
     *  If $set_keys = false (default) then full_names placed to array-values
     *       else into array-keys
     * 
     * Other parameters see in "walkFiles" function description
     * 
     * @param string|array $srcPathOrArr
     * @param bool $nameToKey false: [n]=>FullName, true: [FullName]=>n
     * @param string|array $filePattern
     * @param array $excludePatterns
     * @param bool $getHidden
     * @param bool $getSize
     * @param int $maxDepth
     * @param callable|null $filter_fn
     * @return array
     */
    public static function getFilesArr(
                  $srcPathOrArr,
        bool      $nameToKey = false, // false: [n]=>FullName, true: [FullName]=>n
                  $filePattern = '*',
        array     $excludePatterns = [],
        bool      $getHidden = false,
        bool      $getSize = false,
        int       $maxDepth = 99,
        int       $mode = 1, // 0=ScanDir, 1=Glob, 2=DI(DirectoryIterator), 3=GI (GlobalIterator)
        ?callable $filter_fn = null
    ): array {
        $arr = [];
        foreach(WalkDir::walkFiles($srcPathOrArr, $filePattern, $excludePatterns, $maxDepth, $mode, $getHidden, $getSize, $filter_fn) as $k => $v) {
            if ($getSize) {
                $arr[$k] = $v; // but [fullName]=>FileSize
            } elseif ($nameToKey) {
                $arr[$v] = $k;
            } else {
                $arr[] = $v;
            }
        }
        return $arr;
    }

    public static function benchMarkWalkFiles(
               $srcPathOrArr,
               $filePatterns = ['*.js', '*.php'],
        array  $excludePatterns = [],
        bool   $getHidden = false,
        bool   $getSize = false,
        int    $maxDepth = 99,
        int    $iterations_cnt = 5,
        array  $modesArr = [
            'Glob             ' => 1,
            'ScanDir          ' => 0,
            'DirectoryIterator' => 2,
            'GlobIterator     ' => 3,
        ]
    ) {
        foreach($modesArr as $title => $walkMode) {
            for($i = 0; $i < $iterations_cnt; $i++) {
                $start_time = \microtime(true);
                $cnt = 0;
                $iterator = WalkDir::walkFiles($srcPathOrArr, $filePatterns, $excludePatterns, $maxDepth, $walkMode, $getHidden, $getSize);
                foreach($iterator as $fullName) {
                    $cnt++;
                }
                $end_time = \microtime(true);
                $execution_time = $end_time - $start_time;
                echo "$title: " . $execution_time . " sec.  cnt=$cnt\n";
            }
        }
    }

    /**
     * Generator (yield: [N] => full_name)
     * 
     * Walking the specified $path recursively
     * 
     * Wrapper for walkAllFilesGlob using $file_pattern parameter
     * 
     * The "fnmatch" function is used to match $file_pattern with file-names
     * 
     * @param string|array $srcPathOrArr
     * @param string|array $filePatterns
     * @param int $maxDepth
     * @param bool $getHidden
     * @param array $excludePatterns
     * @param int $walkMode  0=ScanDir, 1=Glob, 2=DI(DirectoryIterator), 3=GI (GlobalIterator)
     */
    public static function walkFiles(
                  $srcPathOrArr,
                  $filePatterns = '*', // string or array. ShortName masks available only
        array     $excludePatterns = [], // ShortName and FullName masks available
        int       $maxDepth = 99,
        int       $walkMode = 1, // 0=ScanDir, 1=Glob, 2=DI(DirectoryIterator), 3=GI (GlobalIterator)
        bool      $getHidden = false,
        bool      $getSize = false,
        ?callable $filter_fn = null
    ) {
        if (is_string($srcPathOrArr)) {
            $srcPathArr = [$srcPathOrArr];
        } elseif (is_array($srcPathOrArr)) {
            $srcPathArr = $srcPathOrArr;
        } else {
            throw new \Exception("srcPath must be string or array of strings");
        }
        unset($srcPathOrArr);

        foreach($excludePatterns as $k => $excludePattern) {
            if (false !== \strpos($excludePattern, '\\')) {
                $excludePatterns[$k] = \strtr($excludePattern, '\\', '/');
            }
        }
        $excludePatterns = \array_unique($excludePatterns);

        if (is_null($filter_fn)) {
            $filter_fn = self::makeFilterFn($filePatterns, $excludePatterns, $walkMode, $getSize);
        }
        foreach($srcPathArr as $srcPath) {
            if ($walkMode === 1) {
                yield from WalkDir::walkAllFilesGlob($srcPath, $maxDepth, $filter_fn, '', $getHidden);
            } elseif ($walkMode === 0) {
                yield from WalkDir::walkAllFilesScanDir($srcPath, $maxDepth, $filter_fn);
            } elseif ($walkMode === 2) {
                yield from WalkDir::walkAllFilesDI($srcPath, $maxDepth, $filter_fn);
            } elseif ($walkMode === 3) {
                yield from WalkDir::walkAllFilesGI($srcPath, $maxDepth, $filter_fn);
            } else {
                throw new \Exception("Unknown walkMode");
            }
        }
    }
    
    public static function makeFilterFn(
              $filePatterns,
        array $excludePatterns = [],
        int   $walkMode = 1,
        bool  $getSize = false
    ) {
        if (\is_string($filePatterns)) {
            $filePatterns = [$filePatterns];
        }
        if (!\is_array($filePatterns)) {
            throw new \Exception("File-patterns must be array or string");
        }
        foreach(array_merge($filePatterns, $excludePatterns) as $filePattern) {
            if (!\is_string($filePattern)) {
                throw new \Exception("All file-patterns must be string");
            }
        }
        foreach($excludePatterns as $k => $excludePattern) {
            $p = \strpos($excludePattern, '/');
            if (false !== $p) {
                $lc = \substr($excludePattern, -1);
                $itIsDir = \is_dir($excludePattern);
                if (!$p) { // if started from "/" then lets started from "*/"
                    $excludePattern = '*' .$excludePattern;
                    if ('/' === $lc) {
                        $itIsDir = true;
                    }
                }
                if ($itIsDir) {
                    if ($lc !== '/' && $lc !== '*') {
                        $excludePattern .= '/';
                    }
                    if ($lc !== '*') {
                        $excludePattern .= '*';
                    }
                }
                $excludePatterns[$k]= $excludePattern;
            }
        }
        if ($getSize) {
            $filter_fn = function($name, $left_path, $isDir, $subDir, $item = null) use ($filePatterns, $excludePatterns) {
                if ($isDir) return true; // dont yield, but add dir
                foreach($filePatterns as $filePattern) {
                    if (\fnmatch($filePattern, $name)) {
                        $fullName = $left_path . $name;
                        foreach($excludePatterns as $excludePattern) {
                            if (false !== \strpos($excludePattern, '/')) {
                                $name = $fullName;
                            }
                            if (\fnmatch($excludePattern, $name)) {
                                return NULL;
                            }
                        }
                        // get file size
                        $file_size = \is_null($item) ? \filesize($fullName) : $item->getSize();
                        return [$fullName => $file_size];
                    }
                }
                return NULL;
            };
        } else {
            $filter_fn = function($name, $left_path, $isDir, $subDir, $item = null) use ($filePatterns, $excludePatterns) {
                if ($isDir) return true; // dont yield, but add dir
                foreach($filePatterns as $filePattern) {
                    if (\fnmatch($filePattern, $name)) {
                        $fullName = $left_path . $name;
                        foreach($excludePatterns as $excludePattern) {
                            if (\strpos($excludePattern, '/')) {
                                $name = $fullName;
                            }
                            if (\fnmatch($excludePattern, $name)) {
                                return NULL;
                            }
                        }
                        return $fullName;
                    }
                }
                return NULL;
            };
        }
        return $filter_fn;
    }

    /**
     * Generator (yield: [N] => full_name   OR   [key]=>value )
     * 
     * Walking the specified $path recursively
     *
     * The "glob" function is used internally.
     * 
     * Always contains '/' separators in full_name (not '\')
     * 
     * $filter_fn -- call a function for each file before yield
     *  By default $filter_fn=NULL all found files will passing in to yield
     *  User defined function $filter_fn can return:
     *   - empty value to skip this element (file or directory)
     *   - for file: non-array value and non-empty -- yield this value as is
     *   - for folder: non-array and non-empty -- folder will added to folders for walking (not yields!)
     *    *for folder recommend return true or false: false means skip this folder, true means use this folder.
     *   - array of 1 element [key] => value, to yield as key => value, where value may have any type
     *    *array-type will yields for files and for folders
     * 
     * The $sub_dir parameter is used only for passing to the $filter_fn function.
     * parameters for $filter_fn function are:
     *   $name      -- short_name of file OR folder (without DIRECTORY_SEPARATOR)
     *   $left_path -- folder (left part of full_path) with '/' in the end, '/'-separated
     *   $is_dir    -- this name is folder (true) or file (false)
     *   $sub_dir   -- subdirectory relative to the path that was called first (empty on first level)
     * 
     * See parameters $path, $get_hidden, $glob_mask -- in "getNames" function definition
     * 
     * $max_depth parameter specifies how deep the sub-folders will be traversal recursively
     *   0 - means that only one specified folder will be traversed
     *   1 - means also first level of sub-folders will be traversed,
     *   etc.
     * 
     * @param string $srcPath
     * @param int $maxDepth
     * @param callable|null $filter_fn
     * @param string $subDir
     * @param bool $getHidden
     * @param string $globMask
     */
    public static function walkAllFilesGlob(
        string    $srcPath,
        int       $maxDepth = 99,
        ?callable $filter_fn = NULL,
        string    $subDir = '',
        bool      $getHidden = true,
        string    $globMask = '*'
    ) {
        $fileItemsArr = WalkDir::getNames($srcPath, $getHidden, $globMask);
        $dirsArr = [];
        $leftPath = \strtr(\realpath($srcPath), '\\', '/');
        if (\count($fileItemsArr) === 1 && !\is_dir($leftPath)) {
            // One file only
            $fileItemsArr = [\basename($leftPath)];
            $leftPath = \dirname($leftPath);
        }
        $leftPath .= '/';
        foreach($fileItemsArr as $shortName) {
            if (\substr($shortName, -1) === \DIRECTORY_SEPARATOR) {
                $isDir = true;
                $shortName = \substr($shortName, 0, -1);
                if ($shortName === '.' || $shortName === '..') continue;
            } else {
                $isDir = false;
            }
            if ($filter_fn) {
                $fullName = $filter_fn($shortName, $leftPath, $isDir, $subDir);
                if (!$fullName) continue;
            } else {
                $fullName = $leftPath . $shortName;
            }
            if ($isDir) {
                $dirsArr[] = $shortName;
            } elseif (!\is_array($fullName)) {
                yield $fullName;
            }
            if (\is_array($fullName)) {
                yield \key($fullName) => \reset($fullName);
            }
        }
        if ($maxDepth > 0) {
            foreach($dirsArr as $shortName) {
                yield from WalkDir::walkAllFilesGlob(
                    $leftPath . $shortName,
                    $maxDepth - 1,
                    $filter_fn,
                    $subDir . '/'. $shortName,
                    $getHidden,
                    $globMask
                );
            }
        }
    }
    
    /**
     * Generator, alternative of walkAllFilesGlob, parameters are similar
     * 
     * The "scandir" function is used internally (instead of "glob").
     * This function included because:
     *  1) the results of "glob" and "scandir" sometimes differ.
     *  2) according to some tests this code is a bit faster
     * 
     * @param string $path
     * @param int $maxDepth
     * @param callable|null $filter_fn
     * @param string $subDir
     */
    public static function walkAllFilesScanDir(
        string $path,
        int $maxDepth = 99,
        ?callable $filter_fn = NULL,
        string $subDir = ''
    ) {
        $fileItemsArr = \scandir($path, \SCANDIR_SORT_NONE);
        if (!\is_array($fileItemsArr)) return;
        $dirs_arr = [];
        $leftPath = \strtr(\realpath($path), '\\', '/') . '/';
        foreach($fileItemsArr as $shortName) {
            if ($shortName === '.' || $shortName === '..') continue;
            $fullName = $leftPath . $shortName;
            $isDir = \is_dir($fullName);
            if ($filter_fn) {
                $fullName = $filter_fn($shortName, $leftPath, $isDir, $subDir);
                if (!$fullName) continue;
            }
            if ($isDir) {
                $dirs_arr[] = $shortName;
            } elseif (!\is_array($fullName)) {
                yield $fullName;
            }
            if (\is_array($fullName)) {
                yield \key($fullName) => \reset($fullName);
            }
        }
        if ($maxDepth > 0) {
            foreach($dirs_arr as $shortName) {
                yield from WalkDir::walkAllFilesScandir($leftPath . $shortName, $maxDepth - 1, $filter_fn, $subDir . '/'. $shortName);
            }
        }
    }

    public static function walkAllFilesGI(
        string $path,
        int $maxDepth = 99,
        ?callable $filter_fn = NULL,
        string $subDir = ''
    ) {
        $leftPath = \strtr(\realpath($path), '\\', '/') . '/';
        $globPattern = $leftPath . '*';
        $iterator = new \GlobIterator($globPattern, \FilesystemIterator::SKIP_DOTS);

        $dirs_arr = [];
        foreach($iterator as $item) {
            $shortName = $item->getBasename();
            $fullName = $leftPath . $shortName;
            $isDir = $item->isDir();

            if ($filter_fn) {
                $fullName = $filter_fn($shortName, $leftPath, $isDir, $subDir, $item);
                if (!$fullName) continue;
            }
            if ($isDir) {
                $dirs_arr[] = $shortName;
            } elseif (!\is_array($fullName)) {
                yield $fullName;
            }
            if (\is_array($fullName)) {
                yield \key($fullName) => \reset($fullName);
            }
        }
        if ($maxDepth > 0) {
            foreach($dirs_arr as $shortName) {
                yield from self::walkAllFilesGI($leftPath . $shortName, $maxDepth - 1, $filter_fn, $subDir . '/' . $shortName);
            }
        }
    }

    public static function walkAllFilesDI(
        string $path,
        int $maxDepth = 99,
        ?callable $filter_fn = null,
        string $subDir = ''
    ) {
        $leftPath = \strtr(\realpath($path), '\\', '/') . '/';
        $iterator = new \DirectoryIterator($leftPath);
        
        foreach ($iterator as $item) {
            if ($item->isDot()) continue;

            $shortName = $item->getFilename();
            $isDir = $item->isDir();

            if ($filter_fn) {
                $result = $filter_fn($shortName, $leftPath, $isDir, $subDir, $item);
                if (!$result) continue;
                if (\is_array($result)) {
                    yield \key($result) => \reset($result);
                    continue;
                }
            }

            $fullName = $leftPath . $shortName;

            if ($isDir) {
                if ($maxDepth > 0) {
                    yield from self::walkAllFilesDI($fullName, $maxDepth - 1, $filter_fn, $subDir . '/' . $shortName);
                }
                continue;
            }

            yield $fullName;
        }
    }
}