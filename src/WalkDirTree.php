<?php
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
 *   foreach($treeObj->walkFiles("*.php") as $fullFile) {
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
     * Конструктор может принимать список путей-источников и список путей исключений
     * Либо может вызываться без параметров
     * пути можно долбавлять позже через addDirTree
     * 
     * @param array<string> $srcPathes
     * @param array<string> $excludePathes
     * @param bool $getHiddenDirs
     */
    public function __construct(
        array $srcPathes = [],
        array $excludePathes = [],
        bool $getHiddenDirs = false
    ) {
        foreach($srcPathes as $basePath) {
            $this->addDirTree($basePath, $excludePathes, $getHiddenDirs);
        }
    }
    
    /**
     * Добавляет дерево директорий в текущий список директорий $dirPathArr
     * Добавление происходит рекурсивно от указанного пути (включительно)
     * Поддерживает массив путей-исключений (эти пути или маски не будут добавлены)
     * 
     * @param string $basePath
     * @param array<string> $excludePathes
     * @param bool $getHidden
     * @param string $globMask
     * @return array<mixed>
     * @throws \InvalidArgumentException
     */
    public function addDirTree(
        string $basePath,
        array $excludePathes = [],
        bool $getHidden = false,
        string $globMask = '*'
    ): array {

        $realPath = \realpath($basePath);
        if (!$realPath || !\is_dir($realPath)) {
            throw new \InvalidArgumentException("basePath is not directory: $basePath");
        }
        $basePath = \strtr($realPath, '\\', '/') . '/';

        $excludePreparedArr = $this->pathAbsPrepareArr($basePath, $excludePathes);

        if ($getHidden) {
            if ($globMask !== '*') {
                throw new \InvalidArgumentException("getHidden can use only with '*'-mask");
            }
            $globMask = '{,.}*';
        }

        return $this->buildDirTree($basePath, $excludePreparedArr, $globMask);
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
     * @param string $fullPath
     * @param array<string,true> $excludeAbsPathArr Пути-исключения в ключах, абсолютный путь
     * @param string $globMask маска выборки директорий
     * @return array<mixed> Дерево директорий.
     */
    private function buildDirTree(
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
                $dirTreeArr[$dirName] = $this->buildDirTree($fullDirPath . '/', $excludeAbsPathArr, $globMask);
            }
        }
        
        $this->dirPathArr[$fullPath] = \count($dirTreeArr);

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
     * @return \Generator
     */
    public function walkFiles(string $globMask = '*', bool $useCache = true, bool $getHidden = false): \Generator {
        // подготовим маску в случае параметра $getHidden
        if ($getHidden) {
            if ($globMask !== '*') {
                throw new \InvalidArgumentException("getHidden can use only with '*'-mask");
            }
            $globMask = '{,.}*';
        }
        foreach($this->dirPathArr as $basePath => $filesArr) {
            if ($useCache && \is_array($filesArr)) {
                $filesArr = $filesArr[$globMask] ?? null;
            }
            $reLoaded = !$useCache || !\is_array($filesArr);
            if ($reLoaded) {
                $filesArr = $this->getFilesArray($basePath, $globMask);
            }
            if ($useCache && $reLoaded) {
                if (!\is_array($this->dirPathArr[$basePath])) {
                    $this->dirPathArr[$basePath] = [];
                }
                $this->dirPathArr[$basePath][$globMask] = $filesArr;
            }
            foreach ($filesArr as $fileName) {
                yield $basePath . $fileName;
            }
        }
    }
}
