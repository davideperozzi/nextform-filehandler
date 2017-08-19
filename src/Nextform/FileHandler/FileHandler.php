<?php

namespace Nextform\FileHandler;

use Nextform\Config\AbstractConfig;
use Nextform\Helpers\FileHelper;

class FileHandler
{
    /**
     * @var string
     *
     * MD5 hash of nextform_files_active
     */
    const FILE_TRIGGER_NAME = '_d1b0162a7d9ae09d7898a36161227c9c';

    /**
     * @var string
     */
    const TEMP_FILE_PREFIX = 'nextform_';

    /**
     * @var AbstractConfig
     */
    private $form = null;

    /**
     * @var string
     */
    private $temp = '';

    /**
     * @var string
     */
    private $defaultErrorMessage = 'Something went wrong';

    /**
     * @var array
     */
    private $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'The uploaded file is too big',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file is too big',
        UPLOAD_ERR_PARTIAL => 'The fiel could not be uploaded completely',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'No tmp dir found to upload the file to',
        UPLOAD_ERR_CANT_WRITE => 'The uploaded file could not be written',
        UPLOAD_ERR_EXTENSION => 'A PHP extension prevented the upload'
    ];

    /**
     * @param AbstractConfig $form
     * @param string $temp
     * @param integer $lifetime (seconds, 12h default)
     */
    public function __construct(AbstractConfig &$form, $temp, $lifetime = 43200)
    {
        if (@is_dir($temp)) {
            if ( ! is_writable($temp)) {
                throw new Exception\TempDestinationNotWritableException(
                    sprintf('Temp destination "%s" is not writable', $temp)
                );
            }
        } else {
            throw new Exception\TempDestinationNoDirException(
                'Temp destination is not a valid dir'
            );
        }

        $this->lifetime = $lifetime;
        $this->temp = rtrim($temp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->form = $form;

        $this->cleanTemp($this->temp);
    }

    private function cleanTemp()
    {
        $files = array_slice(scandir($this->temp), 2);

        foreach ($files as $file) {
            if (substr($file, 0, strlen(self::TEMP_FILE_PREFIX)) != self::TEMP_FILE_PREFIX) {
                continue;
            }

            $path = $this->temp . $file;
            $created = filectime($path);

            if (time() - $created >= $this->lifetime) {
                @unlink($path);
            }
        }
    }

    /**
     * @param array $data
     * @return boolean
     */
    public function isActive($data)
    {
        return array_key_exists(self::FILE_TRIGGER_NAME, $data);
    }

    /**
     * @param array $data
     * @param callable $errorCallback
     * @return boolean
     */
    public function handle(&$data, callable $errorCallback = null)
    {
        if ($this->isActive($data)) {
            $data = $this->proccessData($data, function (&$value) use (&$errorCallback) {
                return $this->exchangeFiles($value, $errorCallback);
            });
        }

        return false;
    }

    /**
     * @param array $data
     * @param callable $callback
     * @return array
     */
    private function proccessData(&$data, callable $callback)
    {
        foreach ($data as $key => $value) {
            if (is_array($value) && FileHelper::isUploadedFile($value)) {
                $data[$key] = $callback($value);
            } elseif (is_array($value)) {
                $data[$key] = $this->proccessData($value, $callback);
            }
        }

        return $data;
    }

    /**
     * @param array &$array
     * @param callable $callback
     * @param array &$lastChild
     */
    private function traverseRecursive(&$array, callable $callback, &$lastChild = null)
    {
        foreach ($array as $key => &$value) {
            $lastChild = &$array;

            if (is_array($value)) {
                $this->traverseRecursive($value, $callback, $array);
            } else {
                $callback($lastChild);
            }
        }
    }

    /**
     * @param array &$file
     * @param string $modify
     * @param array $extract
     * @param callable $callback
     */
    private function traverseFileStructure(&$file, $modify, $extract, callable $callback)
    {
        $extract = array_values(array_unique(array_merge([$modify], $extract)));
        $chunks = [];

        foreach ($extract as $extractKey) {
            if (is_array($file[$extractKey])) {
                $this->traverseRecursive($file[$extractKey], function (&$chunk) use (&$chunks, $extractKey) {
                    $chunks[$extractKey] = &$chunk;
                });
            } else {
                $chunks[$extractKey] = [&$file[$extractKey]];
            }
        }

        if (array_key_exists($modify, $chunks)) {
            foreach ($chunks[$modify] as $i => $value) {
                $params = [];

                foreach ($extract as $extractKey) {
                    if (array_key_exists($extractKey, $chunks)) {
                        if (array_key_exists($i, $chunks[$extractKey])) {
                            $params[] = $chunks[$extractKey][$i];
                        }
                    }
                }

                $chunks[$modify][$i] = call_user_func_array($callback, $params);
            }
        }
    }

    /**
     * @param array
     * @param callable $errorCallback
     * @return array
     */
    private function exchangeFiles(&$file, callable $errorCallback = null)
    {
        $this->traverseFileStructure(
            $file,
            'tmp_name',
            [
                'tmp_name',
                'name',
                'error'
            ],
            function ($tmpName, $name, $error) use (&$errorCallback) {
                if ($error == 0) {
                    $movedFile = $this->moveUploadedFile($tmpName, pathinfo($name, PATHINFO_EXTENSION));

                    if ($movedFile == $tmpName) {
                        if (is_callable($errorCallback)) {
                            $errorCallback($name, -1);
                        }
                    } else {
                        return $movedFile;
                    }
                } else {
                    if (is_callable($errorCallback)) {
                        $errorCallback($name, $error);
                    }
                }

                return $tmpName;
            }
        );

        return $file;
    }

    /**
     * @param string $from
     * @param string $extension
     * @return string
     */
    public function moveUploadedFile($from, $extension)
    {
        $filename = self::TEMP_FILE_PREFIX . md5(time() . $from) . '.' . $extension;
        $destination = $this->temp . $filename;
        $success = move_uploaded_file($from, $destination);

        if ($success) {
            return $destination;
        }

        return $from;
    }

    /**
     * @param array $data
     * @return boolean
     */
    public function removeFilesByData($data)
    {
        $valid = true;

        $this->proccessData($data, function ($value) use (&$valid) {
            $this->traverseFileStructure(
                $value,
                'tmp_name',
                [
                    'tmp_name'
                ],
                function ($tmpName) use (&$valid) {
                    if ( ! @unlink($tmpName)) {
                        $valie = false;
                    }
                }
            );
        });

        return $valid;
    }

    /**
     * @param integer $code
     * @return string
     */
    public function getErrorMessage($code)
    {
        if (array_key_exists($code, $this->errorMessages)) {
            return $this->errorMessages[$code];
        }

        return $this->defaultErrorMessage;
    }

    /**
     * @param integer $code
     * @param string $message
     */
    public function setErrorMessage($code, $message)
    {
        $this->setErrorMessage[$code] = $message;
    }

    /**
     * @param string $message
     */
    public function setDefaultErrorMessage($message)
    {
        $this->defaultErrorMessage = $message;
    }

    /**
     * @return AbstractConfig
     */
    public function getForm()
    {
        return $this->form;
    }
}
