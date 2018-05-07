yii2-filesystem
===============

[![Latest Stable Version](https://poser.pugx.org/kartik-v/yii2-filesystem/v/stable)](https://packagist.org/packages/kartik-v/yii2-filesystem)
[![Latest Unstable Version](https://poser.pugx.org/kartik-v/yii2-filesystem/v/unstable)](https://packagist.org/packages/kartik-v/yii2-filesystem)
[![License](https://poser.pugx.org/kartik-v/yii2-filesystem/license)](https://packagist.org/packages/kartik-v/yii2-filesystem)
[![Total Downloads](https://poser.pugx.org/kartik-v/yii2-filesystem/downloads)](https://packagist.org/packages/kartik-v/yii2-filesystem)
[![Monthly Downloads](https://poser.pugx.org/kartik-v/yii2-filesystem/d/monthly)](https://packagist.org/packages/kartik-v/yii2-filesystem)
[![Daily Downloads](https://poser.pugx.org/kartik-v/yii2-filesystem/d/daily)](https://packagist.org/packages/kartik-v/yii2-filesystem)

File system utilities for managing files and folders including reading, writing and appending to files.

### Install

Either run

```
$ php composer.phar require kartik-v/yii2-filesystem "@dev"
```

or add

```
"kartik-v/yii2-filesystem": "@dev"
```

to the ```require``` section of your `composer.json` file.

## Usage

Example showing creation of a folder instance and search for all the .csv files within it:

```php
use kartik\filesystem\Folder;

$dir = new Folder('/path/to/folder');
$files = $dir->find('.*\.csv');
```

Now you can loop through the files and read from or write/append to the contents or simply delete the file:

```php
use kartik\filesystem\File;

foreach ($files as $file) {
    $file = new File($dir->pwd() . DIRECTORY_SEPARATOR . $file);
    $contents = $file->read();
    // $file->write('I am overwriting the contents of this file');
    // $file->append('I am adding to the bottom of this file.');
    // $file->delete(); // I am deleting this file
    $file->close(); // Be sure to close the file when you're done
}
```

## Examples

```php
use kartik\filesystem\Folder;
use kartik\filesystem\File;

/**
 * Create a new folder with 0755 permissions
 */
$dir = new Folder('/path/to/folder', true, 0755);

/**
 * Create a new file with 0644 permissions
 */
$file = new File('/path/to/file.php', true, 0644);

/**
 * addPathElement: Returns $path with $element added, with correct slash in-between.
 */
$path = Folder::addPathElement('/a/path/for', 'testing');
// $path equals /a/path/for/testing

/**
 * cd: Change directory to $path. Returns false on failure.
 */
$folder = new Folder('/foo');
echo $folder->path; // Prints /foo
$folder->cd('/bar');
echo $folder->path; // Prints /bar
$false = $folder->cd('/non-existent-folder');

/**
 * chmod: Change the mode on a directory structure recursively. 
 *        This includes changing the mode on files as well.
 */
$dir = new Folder();
$dir->chmod('/path/to/folder', 0644, true, ['skip_me.php']);

/**
 * copy: Recursively copy a directory.
 */
$folder1 = new Folder('/path/to/folder1');
$folder1->copy('/path/to/folder2');
// Will put folder1 and all its contents into folder2

$folder = new Folder('/path/to/folder');
$folder->copy([
    'to' => '/path/to/new/folder',
    'from' => '/path/to/copy/from', // Will cause a cd() to occur
    'mode' => 0755,
    'skip' => ['skip-me.php', '.git'],
    'scheme' => Folder::SKIP  // Skip directories/files that already exist.
]);

/**
 * create: Create a directory structure recursively. Can be used to create 
 *         deep path structures like /foo/bar/baz/shoe/horn
 */
$folder = new Folder();
if ($folder->create('foo' . DS . 'bar' . DS . 'baz' . DS . 'shoe' . DS . 'horn')) {
    // Successfully created the nested folders
}

/**
 * delete: Recursively remove directories if the system allows.
 */
$folder = new Folder('foo');
if ($folder->delete()) {
    // Successfully deleted foo and its nested folders
}

/**
 * find: Returns an array of all matching files in the current directory.
 */
// Find all .png in your webroot/img/ folder and sort the results
$dir = new Folder(WWW_ROOT . 'img');
$files = $dir->find('.*\.png', true);
/*
Array
(
    [0] => cake.icon.png
    [1] => test-error-icon.png
    [2] => test-fail-icon.png
    [3] => test-pass-icon.png
    [4] => test-skip-icon.png
)
*/

/**
 * findRecursive: Returns an array of all matching files in and below the current directory.
 */
// Recursively find files beginning with test or index
$dir = new Folder(WWW_ROOT);
$files = $dir->findRecursive('(test|index).*');
/*
Array
(
    [0] => /var/www/demo/index.php
    [1] => /var/www/demo/test.php
    [2] => /var/www/demo/img/test-skip-icon.png
    [3] => /var/www/demo/img/test-fail-icon.png
    [4] => /var/www/demo/img/test-error-icon.png
    [5] => /var/www/demo/img/test-pass-icon.png
)
*/

/**
 * read: Returns an array of the contents of the current directory. The returned
 *       array holds two sub arrays: One of directories and one of files.
 */
// Recursively find files beginning with test or index
$dir = new Folder(WWW_ROOT);
$files = $dir->findRecursive('(test|index).*');
/*
Array
(
    [0] => /var/www/demo/index.php
    [1] => /var/www/demo/test.php
    [2] => /var/www/demo/img/test-skip-icon.png
    [3] => /var/www/demo/img/test-fail-icon.png
    [4] => /var/www/demo/img/test-error-icon.png
    [5] => /var/www/demo/img/test-pass-icon.png
)
*/
```

## License

**yii2-filesystem** is released under the BSD 3-Clause License. See the bundled `LICENSE.md` for details.