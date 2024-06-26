<?php
namespace dynoser\walkdir;

class WalkDirGlob {
    public int $fileCountTotal = 0;
    public int $getNamesSumTotal = 0;

    /**
     * Массив шаблонов, включаемых в результаты (по умолчанию один элемент "*")
     *
     * @var array<string>
     */
    public array $includePatternsArr = [];

    /**
     * Массив шаблонов, исключаемых из результатов
     * 
     * @var array<string>
     */
    public array $excludePatternsArr = [];

    /**
     * 
     * @param array<string>|string $includePatterns шаблоны, по которым будут выбираться файлы
     * @param array<string>|string $excludePatterns шаблоны, по которым будут исключаться файлы из результатов
     * @param int $defaultMaxDepth Максимальная глубина вложенности директорий, на которую будет углубляться по умолчанию
     * @param int $getNamesSumThreshold После какого количества обработанных файлов будет принудительное завершение обработки
     * @param int $fileCountThreshold Сколько максимум файлов будет будет возвращено (только для функции getFilesArr)
     */
    public function __construct(
        array|string $includePatterns = '*',
        array|string $excludePatterns = '',
        public int $defaultMaxDepth = 99,
        public int $getNamesSumThreshold = 0,
        public int $fileCountThreshold = 0
    ) {
        $this->setIncludePatterns($includePatterns);
        if ($excludePatterns) {
            $this->setExcludePatterns($excludePatterns);
        }
    }

    /**
     * Scan files and folders in $base_path and return Array of [ [N] => short_name (file OR folder)]
     * 
     * - folder elements contain DIRECTORY_SEPARATOR at the end
     * - files elements NOT contain DIRECTORY_SEPARATOR at the end
     * 
     * Not recursively! Returns items only from the 1st level of the specified folder.
     * 
     * You can specify one of the following parameters: $get_hidden=true OR $glob_mask
     * if $get_hidden=true then $glob_mask will generate automatically
     * if $get_hidden=false then you can set any $glob_mask ("*" by default)
     * 
     * @param string $basePath
     * @param bool $getHidden
     * @param string $globMask
     * @return array<string>
     * @throws \Exception
     */
    public function getNames(string $basePath, bool $getHidden = false, string $globMask = '*'): array {
        $realPath = \realpath($basePath);
        if (!$realPath) {
            return [];
        }
        $leftLen = \strlen($realPath) + 1;
        if ($getHidden) {
            if ($globMask !== '*') {
                throw new \Exception("get_hidden can use only with '*'-mask");
            }
            $globMask = '{,.}*';
        }
        if (!\is_dir($realPath)) {
            // This is only one file, return an empty string (because we are removing the full left path from the return)
            return [''];
        }
        // It is folder
        $arr = \glob($realPath . \DIRECTORY_SEPARATOR . $globMask,  \GLOB_NOSORT | \GLOB_MARK | \GLOB_BRACE);
        $cnt = $arr ? \count($arr) : 0;
        $ret = [];
        if ($cnt) {
            if ($this->getNamesSumThreshold) {
                $this->getNamesSumTotal += $cnt;
            }
            foreach($arr as $fullName) {
                $ret[] = \substr($fullName, $leftLen);
            }
        }
        return $ret;
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
     * @param int|null $maxDepth
     * @param callable|null $filter_fn
     * @param string $subDir
     * @param bool $getHidden
     * @param string $globMask
     */
    public function walkAllFilesGlob(
        string    $srcPath,
        ?int      $maxDepth = null,
        ?callable $filter_fn = null,
        string    $subDir = '',
        bool      $getHidden = true,
        string    $globMask = '*'
    ): \Generator {
        if ($this->getNamesSumTotal <= $this->getNamesSumThreshold) {

            if (is_null($maxDepth)) {
                $maxDepth = $this->defaultMaxDepth;
            }

            $fileItemsArr = $this->getNames($srcPath, $getHidden, $globMask);

            $dirsArr = [];
            $leftPath = \rtrim(\strtr($srcPath, '\\', '/'), '/');
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
                    if ($shortName === '.' || $shortName === '..') {
                        continue;
                    }
                } else {
                    $isDir = false;
                }
                if ($filter_fn) {
                    $fullName = $filter_fn($shortName, $leftPath, $isDir, $subDir);
                    if (!$fullName) {
                        continue;
                    }
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
                // углубляемся в поддиректори только если не достигнута максимальная глубина
                foreach($dirsArr as $shortName) {
                    yield from $this->walkAllFilesGlob(
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
     * @param string|array<string> $srcPathOrArr
     * @param bool $getHidden
     * @param bool $getSize
     * @param int $maxDepth
     * @param null|callable $filter_fn
     */
    public function walkFiles(
        string|array $srcPathOrArr,
        bool         $getHidden = false,
        bool         $getSize = false,
        int          $maxDepth = 99,
        ?callable    $filter_fn = null
    ): \Generator {
        
        // превратим строковой путь в массив, либо используем готовый массив
        $srcPathArr = \is_string($srcPathOrArr) ? [$srcPathOrArr] : $srcPathOrArr;

        // если не передана функция фильтра, сгенерируем её
        if (\is_null($filter_fn)) {
            $filter_fn = self::makeFilterFn($this->includePatternsArr, $this->excludePatternsArr, $getSize);
        }

        // запускаем обход по каждому переданному пути
        foreach($srcPathArr as $srcPath) {
            yield from $this->walkAllFilesGlob($srcPath, $maxDepth, $filter_fn, '', $getHidden);
        }
    }
    
    /**
     * 
     * @param array<string>|string $includePatterns
     * @return void
     */
    public function setIncludePatterns(array|string $includePatterns): void {
        $this->includePatternsArr = $this->patternsPrepare($includePatterns);
    }
    
    /**
     * 
     * @param array<string>|string $excludePatterns
     * @return void
     */
    public function setExcludePatterns(array|string $excludePatterns): void {
        $this->excludePatternsArr = $this->patternsPrepare($excludePatterns);
    }

    /**
     * 
     * @param array<string>|string $patternsArr
     * @return array<string>
     * @throws \Exception
     */
    public function patternsPrepare(array|string $patternsArr): array {
        if (\is_string($patternsArr)) {
            $patternsArr = [$patternsArr];
        }
        foreach($patternsArr as $k => $patternStr) {
            if (!\is_string($patternStr)) {
                throw new \Exception("all patterns must have string type");
            }
            // если в пути встречаются обратные слеши, заменяем их на прямые
            if (false !== \strpos($patternStr, '\\')) {
                $patternsArr[$k] = \strtr($patternStr, '\\', '/');
            }
        }

        // remove duplicate paths if there are any
        $patternsArr = \array_unique($patternsArr);

        // произведём детальный анализ переданных путей исключений
        foreach($patternsArr as $k => $patternStr) {
            // если путь не содержит разделителей директорий, будем считать это шаблоном файла
            $p = \strpos($patternStr, '/');
            if (false === $p) {
                continue;
            }
            // if there is a directory separator in the path
            $lastChar = \substr($patternStr, -1);
            $itIsDir = \is_dir($patternStr);
            if (!$p) { // if path started from "/"
                //  then lets started from "*/"
                $patternStr = '*' . $patternStr;
                if ('/' === $lastChar) {
                    $itIsDir = true;
                }
            }
            if ($itIsDir) {
                if ($lastChar !== '/' && $lastChar !== '*') {
                    $patternStr .= '/';
                }
                if ($lastChar !== '*') {
                    $patternStr .= '*';
                }
            }
            $patternsArr[$k]= $patternStr;
        }
        return $patternsArr;
    }
    
    /**
     * 
     * @param array<string> $includePatternsArr
     * @param array<string> $excludePatternsArr
     * @param bool $getSize
     * @return callable
     * @throws \Exception
     */
    public static function makeFilterFn(
        array $includePatternsArr,
        array $excludePatternsArr = [],
        bool  $getSize = false
    ): callable {
        // check all elements in arrays are of string type
        foreach(\array_merge($includePatternsArr, $excludePatternsArr) as $filePattern) {
            if (!\is_string($filePattern)) {
                throw new \Exception("All file-patterns must be string");
            }
        }

        if ($getSize) {
            $filter_fn = function($name, $left_path, $isDir, $subDir, $item = null) use ($includePatternsArr, $excludePatternsArr) {
                if ($isDir) {
                    // dont yield, but add dir
                    return true;
                }
                foreach($includePatternsArr as $filePattern) {
                    if (\fnmatch($filePattern, $name)) {
                        $fullName = $left_path . $name;
                        foreach($excludePatternsArr as $excludePattern) {
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
            $filter_fn = function($name, $leftPath, $isDir, $subDir, $item = null) use ($includePatternsArr, $excludePatternsArr) {
                if ($isDir) {
                    // dont yield, but add dir
                    return true;
                }
                foreach($includePatternsArr as $filePattern) {
                    if (\fnmatch($filePattern, $name)) {
                        $fullName = $leftPath . $name;
                        foreach($excludePatternsArr as $excludePattern) {
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
     * @param string|array<string> $srcPathOrArr
     * @param bool $nameToKey false: [n]=>FullName, true: [FullName]=>n
     * @param bool $getHidden
     * @param bool $getSize
     * @param int $maxDepth
     * @param callable|null $filter_fn
     * @return array<string,string>
     */
    public function getFilesArr(
        string|array $srcPathOrArr,
        bool         $nameToKey = false, // false: [n]=>FullName, true: [FullName]=>n
        bool         $getHidden = false,
        bool         $getSize = false,
        int          $maxDepth = 99,
        ?callable $filter_fn = null
    ): array {

        // если не передана функция фильтра, сгенерируем её заранее. Иначе она будет генерироваться каждый раз заново внутри walkFiles
        if (\is_null($filter_fn)) {
            $filter_fn = self::makeFilterFn($this->includePatternsArr, $this->excludePatternsArr, $getSize);
        }

        $arr = [];
        foreach($this->walkFiles($srcPathOrArr, $getHidden, $getSize, $maxDepth, $filter_fn) as $k => $v) {
            if ($this->fileCountThreshold && $this->fileCountTotal > $this->fileCountThreshold) {
                break;
            }
            if ($getSize) {
                $arr[$k] = $v; // but [fullName]=>FileSize
            } elseif ($nameToKey) {
                $arr[$v] = $k;
            } else {
                $arr[] = $v;
            }
            $this->fileCountTotal++;
        }
        return $arr;
    }
}
