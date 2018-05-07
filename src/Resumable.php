<?php
/**
 * @package   yii2-filesystem
 * @author    Kartik Visweswaran <kartikv2@gmail.com>
 * @copyright Copyright &copy; Kartik Visweswaran, Krajee.com, 2018 - 2019
 * @version   1.0.0
 */

namespace kartik\filesystem;

use Yii;
use yii\base\Component;
use yii\helpers\VarDumper;
use yii\web\Request;
use yii\web\Response;

/**
 * The Resumable component provides an object oriented backend for working with resumable.js.
 */
class Resumable extends Component
{
    /**
     * Flag for filename without extension
     */
    const WITHOUT_EXTENSION = true;

    /**
     * @var boolean whether to enable debug mode and logging
     */
    public $debug = false;

    /**
     * @var string the location of the temporary folder
     */
    public $tempFolder = '@runtime/tmp';

    /**
     * @var string the location of the upload folder
     */
    public $uploadFolder = '@web/uploads';

    /**
     * @var boolean whether to delete the temporary folder after processing
     */
    public $deleteTmpFolder = true;

    /**
     * @var array|mixed the files in the web request
     */
    protected $_requestFile;

    /**
     * @var Request the web request component
     */
    protected $_request;

    /**
     * @var Response the web response component
     */
    protected $_response;

    /**
     * @var array the resumable parameters
     */
    protected $_params;

    /**
     * @var string the resumable file name
     */
    protected $_filename;

    /**
     * @var string the resumable file path
     */
    protected $_filepath;

    /**
     * @var string the resumable file extension
     */
    protected $_extension;

    /**
     * @var string the original file name
     */
    protected $_originalFilename;

    /**
     * @var boolean whether upload is complete
     */
    protected $_isUploadComplete = false;

    /**
     * @var array the resumable library options
     */
    protected $_resumableOption = [
        'identifier' => 'identifier',
        'filename' => 'filename',
        'chunkNumber' => 'chunkNumber',
        'chunkSize' => 'chunkSize',
        'totalSize' => 'totalSize',
    ];

    /**
     * Resumable constructor.
     *
     * @param Request|null  $request the web request object instance
     * @param Response|null $response the web response object instance
     * @param array         $config name-value pairs that will be used to initialize the object properties
     */
    public function __construct($request = null, $response = null, $config = [])
    {
        $this->_request = empty($request) ? Yii::$app->request : $request;
        $this->_response = empty($response) ? Yii::$app->response : $response;
        parent::__construct($config);
    }

    /**
     * Finds the extension of the supplied file name
     *
     * @param string $filename the file name
     *
     * @return string
     */
    public static function findExtension($filename)
    {
        $parts = explode('.', basename($filename));
        return end($parts);
    }

    /**
     * Removes the extension of the supplied file name
     *
     * @param string $filename the file name
     *
     * @return string
     */
    public static function removeExtension($filename)
    {
        $parts = explode('.', basename($filename));
        $ext = end($parts); // get extension
        // remove extension from filename if any
        return str_replace(sprintf('.%s', $ext), '', $filename);
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        $this->tempFolder = Yii::getAlias($this->tempFolder);
        $this->uploadFolder = Yii::getAlias($this->uploadFolder);
        if (!empty($this->getResumableParams()) && !empty($this->getRequestFile())) {
            $this->_extension = static::findExtension($this->resumableParam('filename'));
            $this->_originalFilename = $this->resumableParam('filename');
        }
    }

    /**
     * Process the resumable upload
     */
    public function process()
    {
        if (!empty($this->getResumableParams())) {
            if (!empty($this->getRequestFile())) {
                $this->handleChunk();
            } else {
                $this->handleTestChunk();
            }
        }
    }

    /**
     * Return files data from the web request if found
     *
     * @return array|mixed
     */
    public function getRequestFile()
    {
        if (!empty($this->_requestFile)) {
            $this->setRequestFile();
        }
        return $this->_requestFile;
    }

    /**
     * Sets request file
     */
    public function setRequestFile()
    {
        if (!isset($_FILES) || empty($_FILES)) {
            $this->_requestFile = [];
        } else {
            $files = array_values($_FILES);
            $this->_requestFile = array_shift($files);
        }
    }

    /**
     * Whether upload is complete
     *
     * @return boolean
     */
    public function getIsUploadComplete()
    {
        return $this->_isUploadComplete;
    }

    /**
     * Get final filename.
     *
     * @return string Final filename
     */
    public function getFilename()
    {
        return $this->_filename;
    }

    /**
     * Set final filename.
     *
     * @param string Final filename
     */
    public function setFilename($filename)
    {
        $this->_filename = $filename;
    }

    /**
     * Get final filename.
     *
     * @param boolean $withoutExtension whether to get name without extension
     *
     * @return string final filename
     */
    public function getOriginalFilename($withoutExtension = false)
    {
        if ($withoutExtension === self::WITHOUT_EXTENSION) {
            return $this->removeExtension($this->_originalFilename);
        }
        return $this->_originalFilename;
    }

    /**
     * Get final filepath.
     *
     * @return string final filename
     */
    public function getFilepath()
    {
        return $this->_filepath;
    }

    /**
     * Get final extension.
     *
     * @return string Final extension name
     */
    public function getExtension()
    {
        return $this->_extension;
    }

    /**
     * Get resumable parameters
     *
     * @return array
     */
    public function getResumableParams()
    {
        $r = $this->_request;
        return $r->isGet ? $r->get() : ($r->isPost ? $r->post() : null);
    }

    /**
     * Get exclusive file handle
     *
     * @param string $name the file name
     *
     * @return boolean|resource
     */
    public function getExclusiveFileHandle($name)
    {
        // if the file exists, fopen() will raise a warning
        $previous_error_level = error_reporting();
        error_reporting(E_ERROR);
        $handle = fopen($name, 'x');
        error_reporting($previous_error_level);
        return $handle;
    }

    /**
     * Handle test chunk and set response status
     */
    public function handleTestChunk()
    {
        $identifier = $this->resumableParam($this->_resumableOption['identifier']);
        $filename = $this->resumableParam($this->_resumableOption['filename']);
        $chunkNumber = $this->resumableParam($this->_resumableOption['chunkNumber']);
        if (!$this->isChunkUploaded($identifier, $filename, $chunkNumber)) {
            $this->_response->statusCode = 204;
        } else {
            $this->_response->statusCode = 200;
        }
    }

    /**
     * Handle file chunk and set response status
     */
    public function handleChunk()
    {
        $file = $this->getRequestFile();
        $identifier = $this->resumableParam($this->_resumableOption['identifier']);
        $filename = $this->resumableParam($this->_resumableOption['filename']);
        $chunkNumber = $this->resumableParam($this->_resumableOption['chunkNumber']);
        $chunkSize = $this->resumableParam($this->_resumableOption['chunkSize']);
        $totalSize = $this->resumableParam($this->_resumableOption['totalSize']);
        if (!$this->isChunkUploaded($identifier, $filename, $chunkNumber)) {
            $chunkFile = $this->getChunkFile($identifier, $filename, $chunkNumber);
            $this->moveUploadedFile($file['tmp_name'], $chunkFile);
        }
        if ($this->isFileUploadComplete($identifier, $filename, $chunkSize, $totalSize)) {
            $this->_isUploadComplete = true;
            $this->createFileAndDeleteTmp($identifier, $filename);
        }
        $this->_response->statusCode = 200;
    }

    /**
     * Check if file upload is complete
     *
     * @return boolean
     */

    /**
     * Check if file upload is complete
     *
     * @param string $identifier the resumable file identifier
     * @param string $filename the resumable file name
     * @param double $chunkSize the resumable file chunk size
     * @param double $totalSize the resumable file total size
     *
     * @return boolean
     */
    public function isFileUploadComplete($identifier, $filename, $chunkSize, $totalSize)
    {
        if ($chunkSize <= 0) {
            return false;
        }
        $numOfChunks = intval($totalSize / $chunkSize) + ($totalSize % $chunkSize == 0 ? 0 : 1);
        for ($i = 1; $i < $numOfChunks; $i++) {
            if (!$this->isChunkUploaded($identifier, $filename, $i)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if a specific chunk is uploaded
     *
     * @param string  $identifier the resumable file identifier
     * @param string  $filename the resumable file name
     * @param integer $chunkNumber the resumable file chunk number
     *
     * @return boolean
     */
    public function isChunkUploaded($identifier, $filename, $chunkNumber)
    {
        $chunkFile = $this->getChunkFile($identifier, $filename, $chunkNumber);
        $file = new File($chunkFile);
        return $file->exists();
    }

    /**
     * Gets the temporary chunk directory path
     *
     * @param string $identifier the resumable file identifier
     *
     * @return string
     */
    public function tmpChunkDir($identifier)
    {
        $tmpChunkDir = $this->tempFolder . DIRECTORY_SEPARATOR . $identifier;
        if (!file_exists($tmpChunkDir)) {
            mkdir($tmpChunkDir);
        }
        return $tmpChunkDir;
    }

    /**
     * Gets the temporary chunk file name
     *
     * @param string  $filename the resumable file name
     * @param integer $chunkNumber the resumable file chunk number
     *
     * @return string
     */
    public function tmpChunkFilename($filename, $chunkNumber)
    {
        return $filename . '.' . str_pad($chunkNumber, 4, 0, STR_PAD_LEFT);
    }

    /**
     * Log messages with any available additional parameters when [[debug]] flag is enabled.
     *
     * @param string $message the message to be logged
     * @param array  $params additional parameters as key value pairs to be logged
     * @param string $type whether `info`, `warning` or `trace`
     */
    public function log($message, $params = [], $type = 'info')
    {
        if (!$this->debug) {
            return;
        }
        $out = $message;
        if (!empty($params)) {
            $out .= VarDumper::dumpAsString($params);
        }
        if ($type === 'info') {
            Yii::info($out);
        } elseif ($type === 'warning') {
            Yii::warning($out);
        } else {
            Yii::trace($out);
        }
    }

    /**
     * Create final file from chunks
     *
     * @param array  $chunkFiles the list of chunk files
     * @param string $destFile the target destination file
     *
     * @return boolean whether file was successfully generated
     */
    public function createFileFromChunks($chunkFiles, $destFile)
    {
        $this->log('Beginning of create files from chunks');
        natsort($chunkFiles);
        $handle = $this->getExclusiveFileHandle($destFile);
        if (!$handle) {
            return false;
        }
        $destFile = new File($destFile);
        $destFile->handle = $handle;
        foreach ($chunkFiles as $chunkFile) {
            $file = new File($chunkFile);
            $destFile->append($file->read());
            $this->log('Append ', ['chunk file' => $chunkFile]);
        }
        $this->log('End of create files from chunks');
        return $destFile->exists();
    }

    /**
     * Move/rename the uploaded file
     *
     * @param string $file the file name to move
     * @param string $destFile the destination file name with target path
     *
     * @return boolean whether movement was successful
     */
    public function moveUploadedFile($file, $destFile)
    {
        $file = new File($file);
        if ($file->exists()) {
            return $file->copy($destFile);
        }
        return false;
    }

    /**
     * Sets the current web request object instance
     *
     * @param Request $request
     */
    public function setRequest($request)
    {
        $this->_request = $request;
    }

    /**
     * Sets the current web response object instance
     *
     * @param Response $response
     */
    public function setResponse($response)
    {
        $this->_response = $response;
    }

    /**
     * Gets the chunk file name
     *
     * @param string  $identifier the resumable file identifier
     * @param string  $filename the resumable file name
     * @param integer $chunkNumber the resumable file chunk number
     *
     * @return string
     */
    protected function getChunkFile($identifier, $filename, $chunkNumber)
    {
        return $this->tmpChunkDir($identifier) . DIRECTORY_SEPARATOR . $this->tmpChunkFilename($filename, $chunkNumber);
    }

    /**
     * Makes sure the original extension never gets overridden by user defined filename.
     *
     * @param string $filename user-defined filename
     * @param string $originalFilename original filename
     *
     * @return string filename that always has an extension from the original file
     */
    protected function createSafeFilename($filename, $originalFilename)
    {
        $filename = $this->removeExtension($filename);
        $extension = static::findExtension($originalFilename);
        return sprintf('%s.%s', $filename, $extension);
    }

    /**
     * Create the file and delete temporary folder
     *
     * @param string $identifier the resumable file identifier
     * @param string $filename the resumable file name
     */
    protected function createFileAndDeleteTmp($identifier, $filename)
    {
        $tmpFolder = new Folder($this->tmpChunkDir($identifier));
        $chunkFiles = $tmpFolder->read(true, true, true)[1];
        // if the user has set a custom filename
        if ($this->_filename !== null) {
            $finalFilename = $this->createSafeFilename($this->_filename, $filename);
        } else {
            $finalFilename = $filename;
        }
        // replace filename reference by the final file
        $this->_filepath = $this->uploadFolder . DIRECTORY_SEPARATOR . $finalFilename;
        $this->_extension = static::findExtension($this->_filepath);
        if ($this->createFileFromChunks($chunkFiles, $this->_filepath) && $this->deleteTmpFolder) {
            $tmpFolder->delete();
            $this->_isUploadComplete = true;
        }
    }

    /**
     * Gets value of a resumable parameter
     *
     * @param string $shortName the parameter short name
     *
     * @return string|null
     */
    protected function resumableParam($shortName)
    {
        $resumableParams = $this->getResumableParams();
        $param = 'resumable' . ucfirst($shortName);
        return isset($resumableParams[$param]) ? $resumableParams[$param] : null;
    }
}
