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
        }
        else {
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
    public function handle(&$data)
    {
        if (array_key_exists(self::FILE_TRIGGER_NAME, $data)) {
            $data = $this->proccessData($data);
        }

        return false;
    }

    /**
     * @param array $data
     * @return array
     */
    private function proccessData($data)
    {
        foreach ($data as $key => $value) {
            if (is_array($value) && FileHelper::isUploadedFile($value)) {
                $data[$key] = $this->exchangeFiles($value);
            }
            else if (is_array($value)) {
                $data[$key] = $this->proccessData($value);
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
            }
            else {
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
                $this->traverseRecursive($file[$extractKey], function(&$chunk) use (&$chunks, $extractKey) {
                    $chunks[$extractKey] = &$chunk;
                });
            }
            else {
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
     * @return array
     */
    private function exchangeFiles($file)
    {
        $this->traverseFileStructure($file, 'tmp_name', ['tmp_name', 'name', 'error'], function($tmpName, $name, $error){
            if ($error == 0) {
                return $this->moveUploadedFile($tmpName, pathinfo($name, PATHINFO_EXTENSION));
            }

            return $tmpName;
        });

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
     * @return AbstractConfig
     */
    public function getForm()
    {
        return $this->form;
    }
}