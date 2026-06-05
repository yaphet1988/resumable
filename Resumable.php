<?php

namespace app\common;

/**
 * 分片上传服务（对接 resumable.js）
 *
 * 从 dilab/resumable.php 复制核心流程，零外部依赖。
 * - GET:  测试分片是否已上传
 * - POST: 接收分片，所有分片收齐后自动合并为目标文件
 * - return:
 *      200 => ['message' => 'OK'], // Uploading of chunk is complete.
 *      201 => [
 *               'message' => 'File uploaded',
 *               'file' => $_REQUEST['resumableFilename']
 *           ], // Uploading of whole file is complete.
 *      204 => ['message' => 'Chunk not found'],
 *      default => ['message' => 'An error occurred'] //status => 404
 */
class Resumable
{
    public $debug = false;

    public $tempFolder = 'tmp';

    public $uploadFolder = 'test/files/uploads';

    // for testing
    public $deleteTmpFolder = true;

    protected $params;

    protected $chunkFile;

    protected $log;

    protected $filename;

    protected $filepath;

    protected $extension;

    protected $originalFilename;

    protected $isUploadComplete = false;

    const WITHOUT_EXTENSION = true;

    public function __construct()
    {
        $this->preProcess();
    }

    // sets original filename and extenstion, blah blah
    public function preProcess()
    {
        if (!empty($this->resumableParams())) {
            if (!empty($this->file())) {
                $this->extension = $this->findExtension($this->resumableParam('filename'));
                $this->originalFilename = $this->resumableParam('filename');
            }
        }
    }

    public function process()
    {
        if (!empty($this->resumableParams())) {
            if (!empty($this->file())) {
                $this->handleChunk();
            } else {
                $this->handleTestChunk();
            }
        }
    }

    /**
     * Get isUploadComplete
     *
     * @return boolean
     */
    public function isUploadComplete()
    {
        return $this->isUploadComplete;
    }

    /**
     * Set final filename.
     *
     * @param string Final filename
     */
    public function setFilename($filename)
    {
        $this->filename = $filename;

        return $this;
    }

    /**
     * Get final filename.
     *
     * @return string Final filename
     */
    public function getFilename()
    {
        return $this->filename;
    }

    /**
     * Get final filename.
     *
     * @return string Final filename
     */
    public function getOriginalFilename($withoutExtension = false)
    {
        if ($withoutExtension === static::WITHOUT_EXTENSION) {
            return $this->removeExtension($this->originalFilename);
        } else {
            return $this->originalFilename;
        }
    }

    /**
     * Get final filapath.
     *
     * @return string Final filename
     */
    public function getFilepath()
    {
        return $this->filepath;
    }

    /**
     * Get final extension.
     *
     * @return string Final extension name
     */
    public function getExtension()
    {
        return $this->extension;
    }

    /**
     * Makes sure the orginal extension never gets overriden by user defined filename.
     *
     * @param string User defined filename
     * @param string Original filename
     * @return string Filename that always has an extension from the original file
     */
    private function createSafeFilename($filename, $originalFilename)
    {
        $filename = $this->removeExtension($filename);
        $extension = $this->findExtension($originalFilename);

        return sprintf('%s.%s', $filename, $extension);
    }

    public function handleTestChunk()
    {
        $identifier = $this->resumableParam('identifier');
        $filename = $this->resumableParam('filename');
        $chunkNumber = $this->resumableParam('chunkNumber');

        if (!$this->isChunkUploaded($identifier, $filename, $chunkNumber)) {
            return $this->header(404);
        } else {
            return $this->header(200);
        }
    }

    public function handleChunk()
    {
        $file = $this->file();
        $identifier = $this->resumableParam('identifier');
        $filename = $this->resumableParam('filename');
        $chunkNumber = $this->resumableParam('chunkNumber');
        $chunkSize = $this->resumableParam('chunkSize');
        $totalSize = $this->resumableParam('totalSize');

        if (!$this->isChunkUploaded($identifier, $filename, $chunkNumber)) {
            $chunkFile = $this->tmpChunkDir($identifier) . DIRECTORY_SEPARATOR . $this->tmpChunkFilename($filename, $chunkNumber);
            $this->moveUploadedFile($file['tmp_name'], $chunkFile);
        }

        if ($this->isFileUploadComplete($filename, $identifier, $chunkSize, $totalSize)) {
            $this->isUploadComplete = true;
            $this->createFileAndDeleteTmp($identifier, $filename);
        }

        return $this->header(200);
    }

    /**
     * Create the final file from chunks
     */
    private function createFileAndDeleteTmp($identifier, $filename)
    {
        //$tmpFolder = new Folder($this->tmpChunkDir($identifier));
        //$chunkFiles = $tmpFolder->read(true, true, true)[1];
        $tmpFolder     = $this->tmpChunkDir($identifier);
        $chunkFiles = glob($tmpFolder . DIRECTORY_SEPARATOR . '*.part*');

        // if the user has set a custom filename
        if (null !== $this->filename) {
            $finalFilename = $this->createSafeFilename($this->filename, $filename);
        } else {
            $finalFilename = $filename;
        }

        // replace filename reference by the final file
        $this->filepath = $this->uploadFolder . DIRECTORY_SEPARATOR . $finalFilename;
        $this->extension = $this->findExtension($this->filepath);

        if ($this->createFileFromChunks($chunkFiles, $this->filepath) && $this->deleteTmpFolder) {
            //$tmpFolder->delete();
            $this->deleteDirectory($tmpFolder);
            $this->isUploadComplete = true;
        }
    }

    private function resumableParam($shortName)
    {
        $resumableParams = $this->resumableParams();
        if (!isset($resumableParams['resumable' . ucfirst($shortName)])) {
            return null;
        }
        return $resumableParams['resumable' . ucfirst($shortName)];
    }

    public function resumableParams()
    {
        if ($this->is('get')) {
            return $this->data('get');
        }
        if ($this->is('post')) {
            return $this->data('post');
        }
    }

    public function isFileUploadComplete($filename, $identifier, $chunkSize, $totalSize)
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

    public function isChunkUploaded($identifier, $filename, $chunkNumber)
    {
        //$file = new File($this->tmpChunkDir($identifier) . DIRECTORY_SEPARATOR . $this->tmpChunkFilename($filename, $chunkNumber));
        //return $file->exists();
        $chunkPath = $this->tmpChunkDir($identifier) . DIRECTORY_SEPARATOR . $this->tmpChunkFilename($filename, $chunkNumber);
        return file_exists($chunkPath);
    }

    public function tmpChunkDir($identifier)
    {
        $tmpChunkDir = $this->tempFolder . DIRECTORY_SEPARATOR . $identifier;
        if (!file_exists($tmpChunkDir)) {
            mkdir($tmpChunkDir);
        }
        return $tmpChunkDir;
    }

    public function tmpChunkFilename($filename, $chunkNumber)
    {
        return $filename . '.part' . $chunkNumber;
    }

    public function createFileFromChunks($chunkFiles, $destFile)
    {
        $this->log('Beginning of create files from chunks');

        natsort($chunkFiles);

        $fp = fopen($destFile, 'wb');
        if (!$fp) {
            return false;
        }

        foreach ($chunkFiles as $chunkFile) {
            $chunkContent = file_get_contents($chunkFile);
            if ($chunkContent === false) {
                fclose($fp);
                return false;
            }
            fwrite($fp, $chunkContent);
            $this->debugLog('Append chunk: ' . $chunkFile);
        }

        fclose($fp);
        $this->debugLog('End of create files from chunks');

        return file_exists($destFile) && filesize($destFile) > 0;
        /*
        $destFile = new File($destFile, true);
        foreach ($chunkFiles as $chunkFile) {
            $file = new File($chunkFile);
            $destFile->append($file->read());

            $this->log('Append ', ['chunk file' => $chunkFile]);
        }

        $this->log('End of create files from chunks');
        return $destFile->exists();
        */
    }

    public function moveUploadedFile($file, $destFile)
    {
        if (file_exists($file)) {
            return move_uploaded_file($file, $destFile);
        }
        return false;
        /*
        $file = new File($file);
        if ($file->exists()) {
            return $file->copy($destFile);
        }
        return false;
        */
    }

    private function log($msg, $ctx = array())
    {
        if ($this->debug) {
            $this->log->addDebug($msg, $ctx);
        }
    }

    private function findExtension($filename)
    {
        $parts = explode('.', basename($filename));

        return end($parts);
    }

    private function removeExtension($filename)
    {
        $parts = explode('.', basename($filename));
        $ext = end($parts); // get extension

        // remove extension from filename if any
        return str_replace(sprintf('.%s', $ext), '', $filename);
    }

    /**
     * @param $type get/post
     * @return boolean
     */
    private function is($type)
    {
        switch (strtolower($type)) {
            case 'post':
                return isset($_POST) && !empty($_POST);
                break;
            case 'get':
                return isset($_GET) && !empty($_GET);
                break;
        }
        return false;
    }

    /**
     * @param $requestType GET/POST
     * @return mixed
     */
    private function data($requestType)
    {
        switch (strtolower($requestType)) {
            case 'post':
                return isset($_POST) ? $_POST : array();
                break;
            case 'get':
                return isset($_GET) ? $_GET : array();
                break;
        }
        return array();
    }

    /**
     * @return mixed data
     */
    private function file()
    {
        if (!isset($_FILES) || empty($_FILES)) {
            return array();
        }
        $files = array_values($_FILES);
        return array_shift($files);
    }

    /**
     * @param $statusCode
     * @return mixed
     */
    private function header($statusCode)
    {
        if (200 == $statusCode) {
            return header("HTTP/1.0 200 Ok");
        } else if (404 == $statusCode) {
            return header("HTTP/1.0 404 Not Found");
        }
        return header("HTTP/1.0 404 Not Found");
    }

    protected function debugLog(string $msg): void
    {
        if ($this->debug) {
            $line = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $msg);
            //file_put_contents($this->debugLogFile, $line, FILE_APPEND);
        }
    }

    /**
     * 递归删除目录
     */
    protected function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        return rmdir($dir);
    }
}
