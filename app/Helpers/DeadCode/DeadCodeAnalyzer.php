<?php

namespace App\Helpers\DeadCode;

use function foo\func;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

define('EOL', "<br><br>");
define('LB', "\n");
define('NL', "\n\n");

class DeadCodeAnalyzer
{
    /**
     * @var
     * store classes, methods, interfaces separately
     */
    protected $checkFiles;

    protected $parentClassNamespace;
    protected $parentClassName;
    protected $parents;
    protected $methods;
    private $namespaceLists;
    private $constructorEndTokenPosition;

    public $lastToken;
    /**
     * @var array
     * the folder and files which will be ignored for dead code checking
     */
    protected $dirBlackLists = array('Console', 'Exceptions', 'Controllers', 'Requests', 'Middleware', 'Providers', 'Resources', 'DeadCode', 'Mail');
    protected $dirBlackListsToAnalyze = array('Kernel', 'Exceptions', 'Middleware', 'Providers', 'Resources', 'DeadCodeAnalyzer');
//    protected $fileBlackLists = array('Helpers', 'Kernel', 'LdcdController', 'DeadCodeAnalyzer');

    protected $classesToCheck = [];

    /**
     * Initiate Check File keys
     */
    public function initiate()
    {
        $this->checkFiles['classes'] = array();
        $this->checkFiles['methods'] = array();
        $this->checkFiles['interfaces'] = array();

        $this->parentClassNamespace = '';
        $this->parentClassName = '';
    }

    /**
     * @param $path
     * @param string $flag
     * @return array
     * GET FILE PATHS AND RETURNS
     * RETURN FILES
     */
    public function getAllAppDirectoryFiles($path, $flag = '')
    {
        $out = [];
        $results = scandir($path);
        $blackLists = ($flag == "analyze") ? $this->dirBlackListsToAnalyze : $this->dirBlackLists;
        foreach ($results as $result) {
            if ($result === '.' or $result === '..') continue;
            $filename = $path . DIRECTORY_SEPARATOR . $result;
            if (is_dir($filename)) {
                if (in_array($result, $blackLists)) {
                    continue;
                }
                $out = array_merge($out, $this->getAllAppDirectoryFiles($filename, $flag));
            } else {
                $ext = strtolower(substr($filename, -3));

                if ($ext === 'php')
                    $out[] = $filename;

            }
        }
        return $out;
    }

    public function parentClass($class)
    {
        $this->parents = [];
        if ($parent = $class->getParentClass()) {
            $this->parentClassNamespace = $parent->name;
            $parentClass = new \ReflectionClass($this->parentClassNamespace);
            $this->parentClassName = $parentClass->getShortName();

            $this->parents [] = [
                "parentClassNamespace" => $this->parentClassNamespace,
                "parentClassName" => $this->parentClassName
            ];
        }
    }

    /**
     * Get Functions of a Class
     * \ReflectionClass($namespace)
     * namespace
     * filepath
     */
    public function getFunctions($class, $namespace, $filePath)
    {
        $code = file_get_contents($filePath);
        $tokens = new \PHP_Token_Stream($code);
        $totalToken = count($tokens);
        $functions = [];

        for ($tok = 0; $tok < $totalToken; $tok++) {
            if ($tokens[$tok] == "__construct") {
                continue;
            }
            if ($tokens[$tok] == "function" &&
                $tokens[$tok + 1] instanceof \PHP_Token_WHITESPACE &&
                $tokens[$tok + 2] instanceof \PHP_Token_STRING) {
                $position = $tok;
                while ($position) {
                    $position++;
                    if ($tokens[$position] == ')' || $tokens[$position] == '__construct')
                        break;

                    if ($tokens[$position] == '(') {
                        $functions[] = [
                            'name' => $tokens[$position - 1],
                            'flag' => 0,
                            'relationship' => 0
                        ];
                    }
                }

            }
        }


        return $functions;
    }

    public function classStore($namespace, $filePath)
    {
        $class = new \ReflectionClass($namespace);
        $this->parentClass($class);
        $functions = $this->getFunctions($class, $namespace, $filePath);

        $this->checkFiles['classes'][] = [
            'namespace' => $namespace,
            'className' => $class->getShortName(),
            'isInterface' => $class->isInterface(),
            'isTrait' => $class->isTrait(),
            'parentClasses' => $this->parents,
            'methods' => $functions,
            'interface' => [],
            'traits' => []
        ];
    }

    public function getNameSpace($file)
    {
        $tokens = new \PHP_Token_Stream($file);
        $count = count($tokens);
        $namespace = $class = '';
        $classFlag = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i] instanceof \PHP_Token_NAMESPACE) {
                $namespace = $tokens[$i]->getName();
            } elseif ($tokens[$i] instanceof \PHP_Token_CLASS) {

                $class = $tokens[$i]->getName();
                if ($namespace != '') {
                    $class = $namespace . DIRECTORY_SEPARATOR . $class;
                }
                $classFlag = 1;
            } elseif ($classFlag) {
                return $class;
            }
        }
        return $class;
    }

    /**
     * @param $files
     */
    public function storeFileInfo($files)
    {
        foreach ($files as $filePath) {
            $namespace = $this->getNameSpace($filePath);
            if ($namespace)
                $this->classStore($namespace, $filePath);
        }
    }

    public function getDeadCodes()
    {
        $allFiles = $this->getAllAppDirectoryFiles(app_path(), 'analyze');
        $contents = "";
        foreach ($allFiles as $file) {
            $contents .= $file . "\n";
        }
        Storage::disk('local')->put('analyzefile.txt', $contents);
        $this->inspectFiles($allFiles);
    }

    /**
     * @param $tokens
     * @param $totalToken
     * @throws \ReflectionException
     * Get Namespace Lists of a class under observation
     * If any class namespace is replaces with as keyword, we can get the real class name and namespace from here
     */
    public function getNamespaceLists($tokens, $totalToken)
    {
        $this->namespaceLists = [];
        for ($item = 0; $item < $totalToken; $item++) {
            $resourceFlag = 0;
            if ($tokens[$item] == "use" && $tokens[$item + 1] instanceof \PHP_Token_WHITESPACE &&
                ($tokens[$item + 2] == "App" || ($tokens[$item + 2] == "\\" && $tokens[$item + 3] == "App"))) { // only app directory namespace.
                $namespaceString = $replacedNamespaceString = "";
                for ($itemCounter = $item + 2; $tokens[$itemCounter] != ";"; $itemCounter++) {
                    // wont store first separator in namespace AND no space will be concat in the namespace path
                    if (($itemCounter == $item + 2 && $tokens[$itemCounter] == "\\") || $tokens[$itemCounter] == " ") continue;

                    if ($tokens[$itemCounter] == 'Resources') {
                        $resourceFlag = 1;
                        break;
                    }
                    if ($tokens[$itemCounter] == "as") {
                        $replacedNamespaceString .= $tokens[$itemCounter + 2];
                        break;
                    }
                    $namespaceString .= $tokens[$itemCounter];
                }

                $item = $itemCounter;
                if (!$resourceFlag && class_exists($namespaceString)) {
                    $class = new \ReflectionClass($namespaceString);
                    $this->namespaceLists [] = [
                        'namespace' => $namespaceString,
                        'className' => (strlen($replacedNamespaceString) > 0) ? $replacedNamespaceString : $class->getShortName()
                    ];
                }
            }
            // if class starts then there will be no new namespace to add
            if ($tokens[$item] == "class") break;
        }
    }

    function getRealClassName($className)
    {
        $replacedFlag = 0;
        foreach ($this->namespaceLists as $namespace) {
            if (strcmp($namespace["className"], $className) == 0) {
                $replacedFlag = 1;
                return [
                    "namespace" => $namespace["namespace"],
                    "className" => $namespace["className"]
                ];
            }
        }

        if (!$replacedFlag) {
            return [
                "namespace" => 'empty',
                "className" => $className
            ];
        }
    }

    function getFromDI($tokens, $startPosition)
    {
        $classToCheck = [];
        $className = $objectString = "";
        $index = $startPosition;
        $paramFlag = $classFlag = $indexCounter = 0;
        while ($index) {
            $index++;
            if ($tokens[$index] == "}") { // end of constructor
                $this->constructorEndTokenPosition = $index;
                break;
            }
            if ($tokens[$index] == ")") { // end of constructor parameters
                $paramFlag = 1;
            }
            if (!$paramFlag) {
                // get class name
                if (!$classFlag && $tokens[$index] instanceof \PHP_Token_STRING) {
                    $className = $this->getRealClassName($tokens[$index]);
                    $classFlag++;
                }
                // get corresponding object name
                if ($classFlag && $tokens[$index] instanceof \PHP_Token_VARIABLE) {
                    $classFlag--;
                    // by any chance 2ta class er name same hote pare, unique identifier namespace lagbe
                    $classToCheck [] = [
                        "namespace" => $className["namespace"],
                        "className" => $className["className"],
                        "object" => $tokens[$index]
                    ];
                }
            }
            // inside of the constructor
            if ($paramFlag) {
                $indexCounter = $index; // At first it indicates => "{"
                if ($tokens[$index] == '$this') { // here i dont have to think aboumt comments, bcoz if its a comment it will never get "$this"
                    $objectString .= $tokens[$index] . $tokens[$index + 1] . $tokens[$index + 2];
                    $index = $index + 2;
                }
                if ($tokens[$index] instanceof \PHP_Token_VARIABLE && $tokens[$index] != '$this') {
                    // because $this is also a variable
                    foreach ($classToCheck as $key => $value) {
                        if (!strcmp($classToCheck[$key]['object'], $tokens[$index])) {
                            $classToCheck[$key]['object'] = $objectString;
                            $objectString = "";
                        }
                    }
                }
            }
        }
        return $classToCheck;
    }

    function updateMethodFlag($className, $namespace, $methodName, $check = '')
    {
        if ($namespace == 'empty') {
            foreach ($this->checkFiles['classes'] as &$class) {
                $contains = Str::contains($class["namespace"], 'Resources');
                if (!$contains) {
                    if ($className == $class["className"]) {
                        foreach ($class["methods"] as &$method) {
                            if (strcmp($method["name"], $methodName) == 0 && $method["flag"] == 0) {
                                $method["flag"] = 1;
                                break;
                            }
                        }
                    }
                }
            }
        } // if class / namespace is in App directory: it is already chec because this->checkfiles only store none other than app directory files
        elseif (class_exists($namespace)) {

            $class = new \ReflectionClass($namespace);
            $className = $class->getShortName();
            $flag = $thisClass = $parentClassCheck = 0;
            $parentNamespace = "";
            foreach ($this->checkFiles['classes'] as &$class) {
                if ($flag) break;
                if ($namespace == $class["namespace"] && $className == $class["className"]) {
                    foreach ($class["methods"] as &$method) {
                        if (strcmp($method["name"], $methodName) == 0 && $method["flag"] == 0) {
                            $flag = 1;
                            $thisClass = 1;
                            $method["flag"] = 1;
                            // return namespace and method name which flag is set to 1; so that from classesTocheck make empty
                            break;
                        }
                    }
                }
            }
            if (!$thisClass) {
                // if this class is still = 0; that means method is not in this class,
                // either in parent, or in interfaces
                foreach ($this->checkFiles['classes'] as &$file) {
                    if ($namespace == $file["namespace"] && $file["parentClasses"]) { // && $className == $file["className"]
                        foreach ($file["parentClasses"] as $parent) {
                            $parentNamespace .= $parent["parentClassNamespace"];
                        }
                        $this->updateMethodFlag('', $parentNamespace, $methodName);
                        $thisClass = 1;
                        break;
                    }

                }
            }
        }
    }

    function backTrackMethodsCheck($classArrayToCheck, $objectOrNamespace, $methodName, $checker)
    {
        if ($checker == 'this') {
            $this->updateMethodFlag('', $objectOrNamespace, $methodName);
        } //        elseif ($checker == 'empty'){}
        else {
            foreach ($classArrayToCheck as &$class) {
                if ($class['object'] == $objectOrNamespace) {
                    $this->updateMethodFlag($class['className'], $class['namespace'], $methodName);
                }
            }
        }

    }

    public function checkNamespace($model)
    {
        foreach ($this->namespaceLists as $namespace) {
            if ($namespace["className"] == $model) {
                return [
                    "namespace" => $namespace["namespace"],
                    "check" => 1
                ];
            }
        }
        return [
            "namespace" => "",
            "check" => 0
        ];
    }

    function ormChecker($classArrayToCheck, $tokens, $startPosition)
    {
//        $this->classesToCheck [] = [
//            "namespace" => $c["namespace"],
//            "className" => $c["className"],
//            "object" => $tokens[$variable]
//        ];
        $className = $objectString = "";
        $index = $startPosition;
        $paramFlag = $classFlag = $indexCounter = 0;
        // 1 $this->class->method()->
        if ($tokens[$index] == '$this' && $tokens[$index + 1] == '->' &&
            $tokens[$index + 2] instanceof \PHP_Token_STRING && $tokens[$index + 3] == '->' &&
            $tokens[$index + 4] instanceof \PHP_Token_STRING && $tokens[$index + 5] == '(' &&
            $tokens[$index + 6] == ')' && $tokens[$index + 7] == '->'
        ) {
            $objectCheck = $tokens[$index] . $tokens[$index + 1] . $tokens[$index + 2];
            foreach ($this->classesToCheck as &$classes) {
                if (strcmp($classes["object"], $objectCheck) == 0) {
                    $this->updateMethodFlag('', $classes["namespace"], $tokens[$index + 4]);
                    break;
                }
            }
//                break;
        }
        // 2 $this->class->method->
        if ($tokens[$index] == '$this' && $tokens[$index + 1] == '->' &&
            $tokens[$index + 2] instanceof \PHP_Token_STRING && $tokens[$index + 3] == '->' &&
            $tokens[$index + 4] instanceof \PHP_Token_STRING && $tokens[$index + 5] == '->'
        ) {
            $objectCheck = $tokens[$index] . $tokens[$index + 1] . $tokens[$index + 2];
            foreach ($this->classesToCheck as &$classes) {
                if (strcmp($classes["object"], $objectCheck) == 0) {
                    $this->updateMethodFlag('', $classes["namespace"], $tokens[$index + 4]);
                    break;
                }
            }
        }
        // 3 $object->method()->
        if ($tokens[$index] != '$this' && $tokens[$index + 1] == '->' &&
            $tokens[$index + 2] instanceof \PHP_Token_STRING && $tokens[$index + 3] == '(' &&
            $tokens[$index + 4] == ')' && $tokens[$index + 5] == '->'
        ) {
            $objectCheck = $tokens[$index];

            foreach ($this->classesToCheck as &$classes) {
                if (strcmp($classes["object"], $objectCheck) == 0) {
                    echo "Names: ".$classes["className"].EOL;
                    echo "Methh: ".$tokens[$index].'->'.$tokens[$index+2].EOL;
                    $this->updateMethodFlag($classes["className"], $classes["namespace"], $tokens[$index + 2]);
                    break;
                }
            }
        }

        // 4 $object->method->
        if ($tokens[$index] != '$this' && $tokens[$index + 1] == '->' &&
            $tokens[$index + 2] instanceof \PHP_Token_STRING && $tokens[$index + 3] == '->'
        ) {
            $objectCheck = $tokens[$index];
            foreach ($this->classesToCheck as &$classes) {
                if (strcmp($classes["object"], $objectCheck) == 0) {
                    $this->updateMethodFlag($classes["className"], $classes["namespace"], $tokens[$index + 2]);
                    break;
                }
            }
        }
    }

    /*public function startPosition($index, $tokens){
        $count = $flag = 0;
        while (!($tokens[$index] instanceof \PHP_Token_VARIABLE)) {
            $index--;
            $count++;
            if($count == 5 ){
                $flag++; break;
            }
        }

        return $index;
    }*/
    /**
     * @param $files
     * @throws \ReflectionException
     * from here inspection will start
     */
    public function inspectFiles($files)
    {
        foreach ($files as $filePath) {
            $namespace = $this->getNameSpace($filePath);
            $thisClass = "" ;
            $thisClassName = "" ;
            if(class_exists($namespace)){
                $thisClass = new \ReflectionClass($namespace);
                $thisClassName = $thisClass->getShortName();
            }

            $code = file_get_contents($filePath);
            $tokens = new \PHP_Token_Stream($code);
            $totalToken = count($tokens);
            $this->classesToCheck = [];
            $this->getNamespaceLists($tokens, $totalToken); // get used Classes from the file use namespaces

            /**
             * 1. From DI
             * 2. From self::
             * 3. For static method or direct method
             * 4. using new keyword (creating new objec
             * 5. $this keyword
             */
            for ($t = 0; $t < $totalToken; $t++) {

                if ($tokens[$t] == "__construct") { // from constructor using DI get class name/s and object name/s
                    $classesCheck [] = $this->getFromDI($tokens, $t);
                    foreach ($classesCheck as $classes) {
                        foreach ($classes as $class) {
                            $this->classesToCheck [] = [
                                "namespace" => $class["namespace"],
                                "className" => $class['className'],
                                "object" => $class['object']
                            ];
                        }
                    }

                    $t = $this->constructorEndTokenPosition;

                }
                // self method call
                if ($tokens[$t] == "self" && $tokens[$t + 1] == "::") {
                    $this->updateMethodFlag('', $namespace, $tokens[$t + 2]);
                    $t += 2;
                    continue;
                }

                // static method call
                // && $tokens[$t + 1] instanceof \PHP_Token_VARIABLE
                if ($tokens[$t] == "::" && $tokens[$t - 1] != "self"
                    && $tokens[$t + 1] instanceof \PHP_Token_STRING
                    && $tokens[$t + 2] == "(") {

                    // here classname needs
                    if($tokens[$t - 1] == 'static' || (strcmp($thisClassName, $tokens[$t - 1]) == 0))
                        $nameSpace = $namespace;
                    else $nameSpace = "";
//                    $nameSpace = ($tokens[$t - 1] != 'static' && $nameSpace == "" && (strcmp($thisClassName, $tokens[$t - 1]) == 0)) ? $namespace : "";
                    if ($nameSpace == "") {
                        $nameSpaceResult = $this->checkNamespace($tokens[$t - 1]);
                        if ($nameSpaceResult["check"])
                            $nameSpace = $nameSpaceResult['namespace'];

                    }
                    if ($nameSpace != "") {
                        $this->updateMethodFlag('', $nameSpace, $tokens[$t + 1]);
                    }
                }

                // $variable = ClassName::create($request->all());
                // $variable->method()->sync($request->input('services', []));
                // && $tokens[$t + 1] instanceof \PHP_Token_VARIABLE
                if ($tokens[$t] == "::" && $tokens[$t - 1] instanceof \PHP_Token_STRING
                    && ( $tokens[$t - 2] == '=' ||
                        ($tokens[$t - 2] instanceof \PHP_Token_WHITESPACE && $tokens[$t - 3] == '=')
                    )) {
//                    $start = $this->startPosition($t,$tokens);
                    echo 'NM: '.$namespace.EOL;
                    echo 'Class: '.$tokens[$t - 1].EOL;
                    $start = $t;
                    while ($start && !($tokens[$start] instanceof \PHP_Token_VARIABLE)) {
                        $start--;
                    }
//                    echo 'ObjectVariable: '.$tokens[$start].EOL;
                    $c = $this->getRealClassName($tokens[$t-1]); //  If any class namespace is replaces with as keyword, we can get the real class name and namespace from here
//                    echo 'First Name: '.$namespace.EOL;
                    echo 'First: '.$c["namespace"].EOL;
                    $this->classesToCheck [] = [
                        "namespace" => $c["namespace"],
                        "className" => $c["className"],
                        "object" => $tokens[$start]
                    ];
                }

                // new object create check
                if ($tokens[$t] == "new" && $tokens[$t + 2] instanceof \PHP_Token_STRING && $tokens[$t + 3] == "(") {
                    if ($tokens[$t - 1] == '=' || ($tokens[$t - 1] instanceof \PHP_Token_WHITESPACE && $tokens[$t - 2] == '=')) {
                        $variable = $t;
                        while (!($tokens[$variable] instanceof \PHP_Token_VARIABLE)) {
                            $variable--;
                        }
                        $c = $this->getRealClassName($tokens[$t + 2]); //  If any class namespace is replaces with as keyword, we can get the real class name and namespace from here
                        $this->classesToCheck [] = [
                            "namespace" => $c["namespace"],
                            "className" => $c["className"],
                            "object" => $tokens[$variable]
                        ];
                    }

                }

                // now for backtrack method to method flag check the $classesToCheck array
                if ($tokens[$t] instanceof \PHP_Token_VARIABLE) {
                    $object = $method = $checker = "";


                    // for $this, from DI:: like, $this->bank->getResourceById(
                    if ($tokens[$t] == '$this' && $tokens[$t + 1] == '->' &&
                        $tokens[$t + 2] instanceof \PHP_Token_STRING && $tokens[$t + 3] == '->' &&
                        $tokens[$t + 4] instanceof \PHP_Token_STRING && $tokens[$t + 5] == '('
                    ) {
                        $object .= $tokens[$t] . $tokens[$t + 1] . $tokens[$t + 2];
                        $method .= $tokens[$t + 4];
                        $t = $t + 5;
                        $checker .= "DI";
                    }

                    // for calling same class method using $this->totalFromPercentage(
                    if ($tokens[$t] == '$this' && $tokens[$t + 1] == '->' &&
                        $tokens[$t + 2] instanceof \PHP_Token_STRING && $tokens[$t + 3] == '('
                    ) {
                        $object .= $namespace;
                        $method .= $tokens[$t + 2];
                        $t = $t + 3;
                        $checker .= "this";

                    }

                    // another local variable for new keyword, like $variable->method(
                    if ($tokens[$t] instanceof \PHP_Token_VARIABLE && $tokens[$t + 1] == '->' &&
                        $tokens[$t + 2] instanceof \PHP_Token_STRING && $tokens[$t + 3] == '('
                    ) {
                        $object .= $tokens[$t];
                        $method .= $tokens[$t + 2];
                        $checker .= "other";
                    }

                    if ($checker != "")
                        $this->backTrackMethodsCheck($this->classesToCheck, $object, $method, $checker);

                    //for ORM relationship
//                    echo "variable name: ".$tokens[$t].EOL;
                    $this->ormChecker($this->classesToCheck, $tokens, $t);
                }
            }
        }
    }

    function individualCounter()
    {
        $deadMethodWeightText = "Dead method weight: ";
        $deadMethodText = "Dead methods : ";
        $namespaceText = "Name space : ";
        $classNameText = "Class Name : ";
        $out = "";
        $data = [];
        foreach ($this->checkFiles["classes"] as $class) {
            $totalMethodPerClass = $perClassDeadMethodCounter = $mflag = 0;
            foreach ($class["methods"] as $mm) {
                $totalMethodPerClass++;
                if (!$mm["flag"]) {
                    $perClassDeadMethodCounter++;
                    $data [] = ['name' => $mm["name"] . "()"];
                }
            }
            if (!empty($data)) {
                $out .= $namespaceText . $class['namespace'] . LB;
                $out .= $classNameText . $class['className'] . LB;
                $out .= $deadMethodText;
                $cc = 0;
                foreach ($data as $name) {
                    $cc++;
                    $out .= $name['name'];
                    if ($cc < count($data) - 1)
                        $out .= ", ";
                    else $out .= LB;
                }
                $out .= $deadMethodWeightText . number_format((($perClassDeadMethodCounter * 100) / $totalMethodPerClass), 2) . '%' . NL;
                $data = [];
            }
        }
        return $out;
    }

    function totalCounter()
    {
        $counter = 0;
        $deadMethodCounter = 0;
        foreach ($this->checkFiles["classes"] as $class) {
            foreach ($class["methods"] as $mm) {
                $counter++;
                if (!$mm["flag"]) {
                    $deadMethodCounter++;
                }
            }
        }
        return [
            'total' => $counter,
            'dead' => $deadMethodCounter,
            'weight' => number_format((($deadMethodCounter * 100) / $counter), 2) . "%",
        ];
    }

    public
    function resultShow()
    {
        $output = "";
        $resultText = "Result::" . NL;
//        $totalMethodText = "Total class: ";
        $totalMethodText = "Total method:: ";
        $totalDeadMethodText = "Total dead method : ";
        $deadMethodWeightText = "Dead method weight: ";
        $deadMethodInClassText = "Dead method per class::" . NL;

        $totalCounter = $this->totalCounter();
        $output .= $resultText;
        $output .= $totalMethodText . $totalCounter["total"] . LB;
        $output .= $totalDeadMethodText . $totalCounter["dead"] . LB;
        $output .= $deadMethodWeightText . $totalCounter["weight"] . NL;

        $output .= $deadMethodInClassText;
        $output .= $this->individualCounter() . NL;
        echo $output;
        return $output;
    }
}
