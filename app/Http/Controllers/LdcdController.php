<?php

namespace App\Http\Controllers;

use App\Helpers\FileHelpers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Helpers\DeadCodeAnalyzer;
use ReflectionClass;
use ReflectionFunction;

class LdcdController extends Controller
{
    /**
     * store all file paths, here only for app directory
     */
    protected $allAppFiles;

    /**
     * @var
     * helper such as for dead code, long method
     */
    protected $helper;

    public function __construct()
    {
    }



    public function findDeadCodes()
    {
        $this->helper = new DeadCodeAnalyzer();
        $allFiles = $this->helper->getAllAppDirectoryFiles(app_path());
        $contents = "";
        foreach ($allFiles as $file){
            $contents.=$file."\n";
        }
        Storage::disk('local')->put('fileToCheck.txt', $contents);
        $this->helper->initiate();
        $this->helper->storeFileInfo($allFiles);
        $this->helper->getDeadCodes();
        $result = $this->helper->resultShow();
        Storage::disk('local')->put('dead-code-result.txt', $result);
    }
}
