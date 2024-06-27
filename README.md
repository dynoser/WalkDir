# dynoser/walkdir

## Overview

Пакет `dynoser/walkdir` содержит в себе следующие файлы:
 - `WalkDir.php` - первичная версия с различными итераторами и бенчмарками
 - `WalkDirGlob.php` - версия, использующая только функцию glob.
 - `WalkDirTree.php` - наиболее эффективная, на мой взгляд, версия, написанная после всех предыдущих, с учётом полученного в более ранних разработках опыта.

 Каждый файл содержит один класс, не имеет зависимостей.
(достаточно подключить 1 выбранный файл для использования).
 Рекомендую WalkDirTree, поскольку он самый простой и эффективный, работает на php7 и выше.

## class WalkDir

Это старый класс, который вполне рабочий и используется в некоторых проектах, поэтому остаётся в пакете.
Он предоставляет статические функции walkFiles и getFilesArr, позволяющие рекурсивно обойти файлы от указанного пути с заданными путями исключениями (либо вернуть их в виде массива файлов).
У функций есть параметр $mode, позволяющий задать тип итератора, который будет использоваться для обхода:
 - 0 - обход на базе функции `ScanDir`,
 - 1 - обход на базе функции `Glob`,
 - 2 - использовать DI ( `\DirectoryIterator` ),
 - 3 - использовать GI ( `\GlobIterator` )

Также есть статическая функция benchMarkWalkFiles, которая позволяет сравнить скорость режимов обхода указанной папки.

## class WalkDirGlob

В отличие от WalkDir, класс WalkDirGlob использует только glob-функцию для обхода файлов и не имеет параметра $mode.
Функции не статические и порядок параметров не вполне совместимый с WalkDir. Логика работы и форматы данных совпадают с WalkDir. Требует php8.

Для использования надо сначала создать объект new WalkDirGlob() которому передаются массив путей, массив исключений, и другие параметры.

Затем можно вызывать метод объекта walkFiles чтобы сделать обход файлов по маске, либо getFilesArr, чтобы получить файлы в виде массива.

## class WalkDirTree

Этот класс сначала строит дерево директорий по указанным путям, а затем на основе уже готового дерева директорий обходит файлы.

Такой подход даёт заметный выигрыш по производительности в тех случаях, когда нужно несколько раз обойти файлы внутри одного и того же "рабочего дерева папок".

Для использования создаётся объект с передачей массивов папок для построения дерева директорий.

Объект можно создавать с пустыми параметрами, потом отдельно можно добавлять пути при помощи вызовов метода addDirTree.

Для обхода файлов нужно вызывать walkFiles с указанием файловой маски, это генератор, который будет обходить файлы по уже загруженному дереву папок.

Обратим внимание, что генератор передаёт отдельно базовый путь в ключе и имя файла относительно этого пути в значении. Чтобы собрать полное имя файла достаточно соединить ключ и значение, см. примеры ниже.

## Features

- **Efficient Directory Traversal:** Build a tree of directories with support for exclusion paths and masks.
- **File Filtering:** Iterate over files using glob patterns to match specific file types.
- **Caching:** Optional caching of file lists to speed up repeated operations with the same mask.
- **Hidden Files:** Ability to include hidden files and directories in the traversal.

## Installation

To install the `dynoser/walkdir` package, you can use Composer:

```bash
composer require dynoser/walkdir
```

## Usage

Below is a step-by-step guide on how to use the `WalkDirTree` class to traverse directories and retrieve files.

### Step 1: Create a `WalkDirTree` Object

You can create a `WalkDirTree` object with a list of source paths and optional exclusion paths. During object creation, the directory tree is constructed.

```php
use dynoser\walkdir\WalkDirTree;

// Example paths
$sourcePaths = ["/path/to/dir1", "/path/to/dir2"];
$excludePaths = ["/path/to/dir1/exclude"];

$treeObj = new WalkDirTree($sourcePaths, $excludePaths, true);
```

- `$sourcePaths`: List of directories to include in the tree.
- `$excludePaths`: List of directories to exclude from the tree.
- `true`: Optional boolean to include hidden directories.

### Step 2: Iterate Over Files

Once the tree is constructed, you can iterate over the files using a specified mask. This example shows how to iterate over PHP files.

```php
foreach ($treeObj->walkFiles("*.php") as $basePath => $subDirFile) {
    $fillFilePath = $basePath . $subDirFile;
    echo "$fullFilePath\n";
}
```

- `"*.php"`: A glob pattern to filter files (e.g., all PHP files).

### Step 3: (Optional) Caching

If you enable caching, subsequent calls with the same mask will use cached results, reducing the need to re-scan directories.

```php
foreach ($treeObj->walkFiles("*.txt", true) as $basePath => $subDirFile) {
    $fillFilePath = $basePath . $subDirFile;
    echo "$fullFilePath\n";
}
```

- `true`: The second parameter enables caching.


### Example: Using addDirTree

You can add more directories to the tree after creating the object:

```php
$treeObj->addDirTree("/path/to/another/dir", ["/path/to/another/dir/exclude"]);
```

### Example: Preparing Path Arrays

You can use `pathAbsPrepareArr` to convert relative or glob-pattern paths to absolute paths:

```php
$preparedPaths = WalkDirTree::pathAbsPrepareArr("/base/path", ["subdir", "*.pattern"]);
print_r($preparedPaths);
```

## Contributing

Contributions are welcome! Please submit issues or pull requests to the [GitHub repository](https://github.com/dynoser/WalkDir/).

---

This README provides a comprehensive guide to using the `dynoser/walkdir` package, ensuring you can efficiently traverse and manage directories and their contents in your PHP projects.
