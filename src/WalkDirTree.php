<?php
declare(strict_types=1);

namespace dynoser\walkdir;

/**
 * Класс для организации эффективного обхода файлов в диекториях.
 * Сначала выполняет построение дерева директорий (с учётом путей-исключений и масок)
 * Затем обходит файлы (с поддержкой масок) по уже имеющемуся дереву директорий.
 * Опционально может кэшировать обход файлов по маскам
 *  (при кэшировании следующий обход с одной и той же маской будет брать файлы из памяти)
 * 
 * Рекомендуемый сценарий вызова:
 *  1) создаём объект, при этом будет построено дерево директорий
 *    $treeObj = new \V\path\WalkDirTree([ массив путей-источников ], [ массив путей-исключений ], true);
 *  2) обходим файлы по уже готовому дереву директорий, указывая маску
 *   foreach($treeObj->walkFiles("*.php") as $basePath => $subDirFile) {
 *      $fullFile = $basePath . $subDirFile;
 *      echo "$fullFile \n";
 *   }
 * 
 */
class WalkDirTree {

    /**
     * Список папок, с которыми идёт работа
     * Ключи
     *  - полный путь к папке со слешем в конце, слэши всегда прямые (обратные конвертируются в прямые)
     * Значения
     *  - либо число, означающее количество вложенных папок,
     *  - либо массив, содержащий короткие имена файлов внутри этой папки
     * 
     * @var array<string,mixed>
     */
    public array $dirPathArr = [];
    
    /**
     * Массив базовых путей, ключ - базовый путь, значение - внутренний числовой идентификатор пути basePathId
     *  (пути со слешами в конце)
     * @var array<string,int>
     */
    public array $basePathToIdArr = [];
    
    /**
     * Массив обратной ассоциации для $basePathToIdArr [basePathId] => basePath (пути со слешами в конце)
     * Фактически повторяет $srcPathes, переданный в конструктор, и используется как источник для reLoadTree
     * @var array<int,string>
     */
    public array $pathIdToPathArr = [];
    
    /**
     * Копия массива $excludePathes переданного в конструктор, используется для reLoadTree
     * (поскольку public, можно внести изменения и затем вызвать reLoadTree)
     * @var array<string>
     */
    public array $excludePathes;
    
    /**
     * Копия параметра переданного в конструктор, используется для reLoadTree
     * @var bool $getHiddenDirs
     */
    public bool $getHiddenDirs;
    
    /**
     * Конструктор может принимать список путей-источников и список путей исключений
     * Либо может вызываться без параметров
     * пути можно долбавлять позже через addDirTree
     * 
     * @param array<int,string> $srcPathes
     * @param array<string> $excludePathes
     * @param bool $getHiddenDirs
     */
    public function __construct(
        array $srcPathes = [],
        array $excludePathes = [],
        bool $getHiddenDirs = false
    ) {
        $this->excludePathes = $excludePathes;
        $this->getHiddenDirs = $getHiddenDirs;
        $this->reLoadTree($srcPathes);
    }
    
    /**
     * Сбрасывает построенное дерево и строит заново.
     * Первый раз вызывается из конструктора.
     * 
     * @param null|array<int,string> $srcPathes
     * @return void
     */
    public function reLoadTree(array $srcPathes = null): void {
        $srcPathes = $srcPathes ?? $this->pathIdToPathArr;
        $this->basePathToIdArr = [];
        $this->pathIdToPathArr = [];
        $this->dirPathArr = [];
        foreach($srcPathes as $basePathId => $basePath) {
            $this->addDirTree($basePath, $this->excludePathes, $this->getHiddenDirs, '*', $basePathId);
        }
    }
    
    /**
     * Добавляет дерево директорий в текущий список директорий $dirPathArr
     * Добавление происходит рекурсивно от указанного пути (включительно)
     * Поддерживает массив путей-исключений (эти пути или маски не будут добавлены)
     * 
     * @param string $basePathStr
     * @param array<string> $excludePathes
     * @param bool $getHidden
     * @param string $globMask
     * @param int $basePathId идентификатор пути базового пути  (если null то будет дан следующий свободный номер)
     * @return array<mixed>
     * @throws \InvalidArgumentException
     */
    public function addDirTree(
        string $basePathStr,
        array $excludePathes = [],
        bool $getHidden = false,
        string $globMask = '*',
        ?int $basePathId = null
    ): array {

        $realPath = \realpath($basePathStr);
        if (!$realPath || !\is_dir($realPath)) {
            throw new \InvalidArgumentException("basePath is not directory: $basePathStr");
        }
        $basePathStr = \strtr($realPath, '\\', '/') . '/';

        $excludePreparedArr = $this->pathAbsPrepareArr($basePathStr, $excludePathes);

        if ($getHidden) {
            if ($globMask !== '*') {
                throw new \InvalidArgumentException("getHidden can use only with '*'-mask");
            }
            $globMask = '{,.}*';
        }

        // не допускаем повторного добавления одного и того же пути, потому что если он добавляется
        //  с разными параметры, то может получиться путаница.
        if (isset($this->basePathToIdArr[$basePathStr])) {
            throw new \InvalidArgumentException("already exists basePath=$basePathStr");
        }
        if (isset($basePathId)) {
            // если идентификатор передан, то он должен отсутствовать в массивах
            if (isset($this->pathIdToPathArr[$basePathId])) {
                throw new \InvalidArgumentException("already defined basePathId=$basePathId");
            }
        } else {
            // если идентификатор не передан, значит найдём следующий пустой номер
            $basePathId = \count($this->basePathToIdArr);
            // Увеличиваем это значение до тех пор, пока оно встречается в массивах
            while (isset($this->pathIdToPathArr[$basePathId])) {
                $basePathId++;
            }
        }
        // добавляем значения в оба массива сразу.
        $this->basePathToIdArr[$basePathStr] = $basePathId;
        $this->pathIdToPathArr[$basePathId] = $basePathStr;
    
        return $this->buildDirTree($basePathId, $basePathStr, $excludePreparedArr, $globMask);
    }
    
    /**
     * Принимает на входе базовый путь и массив путей,
     * возвращает массив с абсолютными путями в ключах со значениями всегда true.
     * возвращаемые абсолютные пути всегда проверяются на существование и начинаются на базовый путь.
     * 
     * Во входном массиве путей могут быть как абсолютные значения путей, так и относительные.
     * Сначала проверяются абсолютные, а если не находится - тогда относительные.
     * Относительные пути получаются пристыковкой базового пути и значения.
     * Если ни абсолютного, ни относительного пути не найдено, значение в результат не добавляется.
     * Возможны также маски в формате функции glob, признаком маски является наличие символов ?*[{
     * если по маске удаётся найти какие-либо директории, они все добавляются в результат.
     * 
     * @param string $basePath базовый путь, на который будут начинаться все результаты
     * @param array<string> $patternsArr массив абсолютных или относительных путей или масок
     * @return array<string,true> В ключах будут существующие абсолютные пути, начинающиеся на базовый путь
     * @throws \InvalidArgumentException
     */
    public static function pathAbsPrepareArr(string $basePath, array $patternsArr): array {
        $resultsArr = [];
        $l = \strlen($basePath);
        foreach($patternsArr as $patternStr) {
            if (!\is_string($patternStr)) {
                throw new \InvalidArgumentException("all patterns must have string type");
            }
            if ('' === $patternStr) {
                continue;
            }
            if (false === \strpbrk($patternStr, '?*[{')) {
                // если в шаблоне нет масочных символов

                // если в пути встречаются обратные слеши, заменяем их на прямые
                if (false !== \strpos($patternStr, '\\')) {
                    $patternStr = \strtr($patternStr, '\\', '/');
                }
                
                if ((\substr($patternStr, 0, $l) === $basePath) && \is_dir($patternStr)) {
                    // если строка начинается на basePath (он со слешем в конце)
                    // и есть такая директория, значит указана абсолютная директория
                    $patternStr = \rtrim($patternStr, '/'); //удалим слэш в конце если он есть
                    $resultsArr[$patternStr] = true;
                    continue;
                }
                // предположим что указан относительный путь, тогда обрежем слеэши по краям
                // и пристыкуем к нему basePath (он со слешем в конце)
                $patternStr = $basePath . \trim($patternStr, '/');
                if (\is_dir($patternStr)) {
                    // если такая директория есть, добавляем её
                    $resultsArr[$patternStr] = true;
                    continue;
                }
            } else {
                // если в шаблоне есть масочные символы, то попробуем найти директории, соответствующие этой маске
                $dirArr = [];
                if (\substr($patternStr, 0, $l) === $basePath) {
                    $dirArr = \glob($patternStr, \GLOB_ONLYDIR | \GLOB_NOSORT | \GLOB_BRACE);
                }
                if (!$dirArr) {
                    $dirArr = \glob($basePath . $patternStr, \GLOB_ONLYDIR | \GLOB_NOSORT | \GLOB_BRACE);
                }
                if ($dirArr) {
                    foreach($dirArr as $dirPath) {
                        if (\substr($dirPath, 0, $l) === $basePath) {
                            $dirPath = \strtr( $dirPath, '\\', '/');
                            $resultsArr[$dirPath] = true;
                        }
                    }
                }
            }
        }
        return $resultsArr;
    }

    /**
     * Добавляет в массив $this->dirPathArr папки, которые рекурсивно обнаруживаются в исходном пути $fullPath
     * при этом из результатов выбрасываются пути-исключения
     * 
     * @param int $basePathId идентификатор пути базового пути
     * @param string $fullPath полный путь, с которого рекурсивно проходятся директории
     * @param array<string,true> $excludeAbsPathArr пути-исключения в ключах, абсолютный путь
     * @param string $globMask маска выборки директорий
     * @return array<mixed> Дерево директорий.
     */
    private function buildDirTree(
        int $basePathId,
        string $fullPath,
        array $excludeAbsPathArr = [],
        string $globMask = '*'
    ): array {
        $leftLen = \strlen($fullPath);

        $dirArr = \glob($fullPath .  $globMask, \GLOB_ONLYDIR | \GLOB_NOSORT | \GLOB_BRACE);

        $dirTreeArr = [];

        if ($dirArr) {
            foreach ($dirArr as $fullSubDir) {
                $dirName = \substr($fullSubDir, $leftLen);
                if ($dirName === '.' || $dirName === '..') {
                    continue;
                }
                $fullDirPath = $fullPath . $dirName;
                if (\array_key_exists($fullDirPath, $excludeAbsPathArr)) {
                    continue;
                }
                $dirTreeArr[$dirName] = $this->buildDirTree($basePathId, $fullDirPath . '/', $excludeAbsPathArr, $globMask);
            }
        }
        
        $this->dirPathArr[$fullPath] = $basePathId;

        return $dirTreeArr;
    }
    
    /**
     * Получает только короткие имена файлов (без путей) из указанной директории (не рекурсивно, без директорий)
     * 
     * @param string $basePath must ended with "/"
     * @param string $globMask mask for glob function
     * @return array<string> массив только имён файлов (без путей)
     * @throws \InvalidArgumentException
     */
    private function getFilesArray(string $basePath, string $globMask = '*'): array {
        $ret = [];
        $leftLen = \strlen($basePath); // must ended by '/'
        $arr = \glob($basePath . $globMask,  \GLOB_NOSORT | \GLOB_MARK | \GLOB_BRACE);
        if ($arr) {
            foreach($arr as $fullName) {
                if (\substr($fullName, -1) !== \DIRECTORY_SEPARATOR) {
                    $ret[] = \substr($fullName, $leftLen);
                }
            }
        }
        return $ret;
    }
    
    /**
     * Итератор для файлов в дереве директорий
     * 
     * @param string $globMask
     * @param bool $useCache
     * @param bool $getHidden
     * @yield array<string,string>
     * @return \Generator
     */
    public function walkFiles(string $globMask = '*', bool $useCache = true, bool $getHidden = false): \Generator {
        // подготовим маску в случае параметра $getHidden
        if ($getHidden) {
            if ($globMask !== '*') {
                throw new \InvalidArgumentException("getHidden can use only with '*'-mask");
            }
            $globMask = '{,.}*';
        } elseif ($globMask === '') {
            throw new \InvalidArgumentException("Empty mask");
        }
        foreach ($this->dirPathArr as $dirPath => $intOrArr) {
            /**
             * @var int $basePathId
             */
                $basePathId = $intOrArr[''];

            $fromCache = $useCache && isset($intOrArr[$globMask]);
            /**
             * @var array<string> $filesArr
             */
            $filesArr = $fromCache ? $intOrArr[$globMask] : $this->getFilesArray($dirPath, $globMask);
            if ($useCache && !$fromCache) {
                $this->dirPathArr[$dirPath][$globMask] = $filesArr;
            }
            $basePath = $this->pathIdToPathArr[$basePathId];
            $bpl = \strlen($basePath);
            foreach ($filesArr as $fileName) {
                $fullFile = $dirPath . $fileName;
                yield $basePath => substr($fullFile, $bpl);
            }
        }
    }
}
