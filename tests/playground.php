<?php

require realpath(__DIR__ . '/../vendor/autoload.php');

use Nextform\Config\XmlConfig;
use Nextform\FileHandler\FileHandler;
use Nextform\Renderer\Renderer;

$form = new XmlConfig('
    <form name="form2" method="POST" enctype="multipart/form-data">
        <input name="_d1b0162a7d9ae09d7898a36161227c9c" type="text" hidden=""/>
        <input type="text" name="test2" placeholder="Test 2">
            <validation required="true">
                <errors>
                    <required>Fill this out</required>
                </errors>
            </validation>
        </input>
        <input type="file" name="testfile[]" multiple="true">
            <validation required="true" />
        </input>
        <input type="file" name="testfile2">
            <validation required="true" />
        </input>
        <input type="submit" value="OK! (2)" />
    </form>
', true);

echo '<pre>';

$formData = array_merge($_POST, $_FILES);

$fileHandler = new FileHandler($form, __DIR__ . '/assets/temp/');
$fileHandler->handle($formData);

$renderer = new Renderer($form);
echo $renderer->render()->each(function ($chunk) {
    $chunk->wrap('<div>%s</div>');
});
