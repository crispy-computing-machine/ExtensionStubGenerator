<?php
namespace CoreLibrarys;

use ReflectionClass;
use ReflectionException;
use ReflectionExtension;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;

/**
 * Class ExtensionStubGenerator
 */
class ExtensionStubGenerator {

    /**
     * @var array
     */
    private $global = [];

    /**
     * @var array
     */
    private $namespaces = [];

    /**
     * ExtensionStubGenerator constructor.
     * @param $extension
     */
    function __construct($extension)
    {
        try {
            $extension = new ReflectionExtension($extension);
        } catch (ReflectionException $e) {
            /** @noinspection ForgottenDebugOutputInspection */
            error_log($e);
        }

        // Process constants
        $constants = $extension->getConstants();
        foreach ($constants as $cname => $cvalue) {
            $this->applyNamespaces($this->_constant($cname, $cvalue));
        }

        // Process functions
        $functions = $extension->getFunctions();
        foreach ($functions as $function) {
            $this->applyNamespaces($this->_function($function));
        }

        // Process classes
        $classes = $extension->getClasses();
        foreach ($classes as $class) {
            $this->applyNamespaces($this->_class($class));
        }

    }

    /**
     * Create the stub file contents
     * @return string
     */
    function generate() {

        $res = '<?php' . PHP_EOL;
        $res .= '/**' . PHP_EOL . ' * Generated stub file for code completion purposes' . PHP_EOL . ' */';
        $res .= PHP_EOL . PHP_EOL;
        foreach ($this->namespaces as $ns => $php) {
            $res .= "namespace $ns {".PHP_EOL;
            $res .= implode(PHP_EOL.PHP_EOL, $php);
            $res .= PHP_EOL.'}'.PHP_EOL;
        }
        $res .= implode(PHP_EOL.PHP_EOL, $this->global);
        return $res;
    }

    /**
     * Apply namespaces to code
     * @param array $item
     */
    function applyNamespaces(array $item) {

        $ns = $item['ns'];
        $php = $item['php'];
        if($ns === null) {
            $this->global[] = $php;
        } else {
            if(!isset($this->namespaces[$ns])) {
                $this->namespaces[$ns] = [];
            }
            $this->namespaces[$ns][] = $php;
        }
    }

    /**
     * Process constant
     * @param string $cname
     * @param $cvalue
     * @return array
     */
    function _constant(string $cname, $cvalue) {
        $split = explode("\\", $cname);
        $name = array_pop($split);
        $namespace = null;
        if(count($split)) {
            $namespace .= implode("\\", $split);
        }
        $res = 'const '.$name.'='.var_export($cvalue, true) .';';
        return [
            'ns' => $namespace,
            'php' => $res
        ];
    }

    /**
     * Process class
     * @param ReflectionClass $c
     * @return array
     */
    function _class(ReflectionClass $c) {

        $res = $this->_classModifiers($c).$c->getShortName().' ';
        if($c->getParentClass()) {
            $res .= "extends \\".$c->getParentClass()->getName().' ';
        }

        if(count($c->getInterfaces())>0) {
            $res .= 'implements ';
            $res .= implode(', ', array_map(function(ReflectionClass $i){
                return "\\".$i->getName();
            }, $c->getInterfaces()));
        }

        $res .= ' {'.PHP_EOL;
        foreach ($c->getTraits() as $t) {
            $res .= TAB_SPACES."use ".$t->getName().';'.PHP_EOL;
        }

        foreach ($c->getConstants() as $k => $v) {
            $res .= TAB_SPACES."const ".$k.'='.var_export($v, true).';'.PHP_EOL;
        }

        foreach ($c->getProperties() as $p) {
            $res.= $this->_property($p);
        }

        foreach ($c->getMethods() as $m) {
            if($m->getDeclaringClass() === $c) {
                $res .= $this->_method($m);
            }
        }
        $res .= '}';
        return [
            'ns'  => $c->inNamespace() ? $c->getNamespaceName() : null,
            'php' => $res
        ];
    }

    /**
     * Process functions
     * @param ReflectionFunction $f
     * @return array
     */
    function _function(ReflectionFunction $f) {
        $res = '';
        if($f->getDocComment()) {
            $res .= $f->getDocComment();
        }
        $res .= $f->getShortName() . '(' .
            implode(', ', array_map([$this,'_argument'], $f->getParameters())).')';
        if($f->getReturnType()) {
            $res .= ': '. $this->_type($f->getReturnType());
        }
        $res .= ' {}';
        return [
            'ns'  => $f->inNamespace() ? $f->getNamespaceName() : null,
            'php' => $res
        ];
    }

    /**
     * Process class modifiers
     * @param ReflectionClass $c
     * @return string
     */
    function _classModifiers(ReflectionClass $c) {
        $res = '';
        if($c->isAbstract()) {
            $res .= 'abstract ';
        }
        if($c->isFinal()) {
            $res .= 'final ';
        }
        if($c->isTrait()) {
            $res .= 'trait ';
        } else if ($c->isInterface()) {
            $res .= 'interface ';
        } else {
            $res .= 'class ';
        }
        return $res;
    }

    /**
     * Process class property
     * @param ReflectionProperty $p
     * @return string
     */
    function _property(ReflectionProperty $p) {
        $res = TAB_SPACES;
        if($p->getDocComment()) {
            $res .= $p->getDocComment().PHP_EOL.TAB_SPACES;
        }
        $res .= $this->_propModifiers($p).'$'.$p->getName().';'.PHP_EOL;
        return $res;
    }

    /**
     * process property modifiers
     * @param ReflectionProperty $p
     * @return string
     */
    function _propModifiers(ReflectionProperty $p) {
        $res = '';
        if($p->isPublic()) {
            $res .= 'public ';
        }
        if($p->isProtected()) {
            $res .= 'protected ';
        }
        if($p->isPrivate()) {
            $res .= 'private ';
        }
        if($p->isStatic()) {
            $res .= 'static ';
        }
        return $res;
    }

    /**
     * Process class methods
     * @param ReflectionMethod $m
     * @return string
     */
    function _method(ReflectionMethod $m) {

        $res = TAB_SPACES;
        if($m->getDocComment()) {
            $res .= $m->getDocComment().PHP_EOL.TAB_SPACES;
        }
        $res .= $this->_methodModifiers($m).'function '.$m->getName().' ('.
            implode(', ', array_map('_argument', $m->getParameters())).')';
        if($m->hasReturnType()) {
            $res .= ': '. $this->_type($m->getReturnType());
        }
        if(!$m->isAbstract()) {
            $res .= ' {}'.PHP_EOL;
        } else {
            $res .= ';'.PHP_EOL;
        }
        return $res;
    }

    /**
     * Process method modifiers
     * @param ReflectionMethod $m
     * @return string
     */
    function _methodModifiers(ReflectionMethod $m) {
        $res = '';
        if($m->isPublic()) {
            $res .= 'public ';
        }
        if($m->isProtected()) {
            $res .= 'protected ';
        }
        if($m->isPrivate()) {
            $res .= 'private ';
        }
        if($m->isAbstract()) {
            $res .= 'abstract ';
        }
        if($m->isStatic()) {
            $res .= 'static ';
        }
        if($m->isFinal()) {
            $res .= 'final ';
        }
        return $res;
    }

    /**
     * Process argument types/default
     * @param ReflectionParameter $p
     * @return string
     */
    function _argument(ReflectionParameter $p) {
        $res = '';
        if($type = $p->getType()) {
            $res.= $this->_type($type).' ';
        }
        if($p->isPassedByReference()) {
            $res .= '&';
        }
        if($p->isVariadic()) {
            $res .= '...';
        }
        $res .= '$'.$p->getName();
        if($p->isOptional()) {
            if($p->isDefaultValueAvailable()) {
                try {
                    $res .= '=' . var_export($p->getDefaultValue(), true);
                } catch (ReflectionException $e) {
                    /** @noinspection ForgottenDebugOutputInspection */
                    error_log($e);
                }
            } else {
                $res .= "='<?>'";
            }
        }
        return $res;
    }

    /**
     * Process property type
     * @param ReflectionType $t
     * @return string
     */
    function _type(ReflectionType $t) {
        if($t->isBuiltin()) {
            return "$t";
        }

        return "\\$t";
    }
}