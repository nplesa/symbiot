<?php declare(strict_types = 1);

// osfsl-/var/www/symbiot.npsoft.ro/vendor/composer/../laravel/framework/src/Illuminate/Routing/ControllerMiddlewareOptions.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Illuminate\Routing\ControllerMiddlewareOptions
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-ab10f435eb22808382b2ed39d2ef01fe0fac66cd6c1c524cc2d2dae3768ca57f-8.4.16-6.70.0.3',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Illuminate\\Routing\\ControllerMiddlewareOptions',
        'filename' => '/var/www/symbiot.npsoft.ro/vendor/composer/../laravel/framework/src/Illuminate/Routing/ControllerMiddlewareOptions.php',
      ),
    ),
    'namespace' => 'Illuminate\\Routing',
    'name' => 'Illuminate\\Routing\\ControllerMiddlewareOptions',
    'shortName' => 'ControllerMiddlewareOptions',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 5,
    'endLine' => 49,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => NULL,
    'implementsClassNames' => 
    array (
    ),
    'traitClassNames' => 
    array (
    ),
    'immediateConstants' => 
    array (
    ),
    'immediateProperties' => 
    array (
      'options' => 
      array (
        'declaringClassName' => 'Illuminate\\Routing\\ControllerMiddlewareOptions',
        'implementingClassName' => 'Illuminate\\Routing\\ControllerMiddlewareOptions',
        'name' => 'options',
        'modifiers' => 2,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/**
 * The middleware options.
 *
 * @var array
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 12,
        'endLine' => 12,
        'startColumn' => 5,
        'endColumn' => 23,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
    ),
    'immediateMethods' => 
    array (
      '__construct' => 
      array (
        'name' => '__construct',
        'parameters' => 
        array (
          'options' => 
          array (
            'name' => 'options',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => true,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 19,
            'endLine' => 19,
            'startColumn' => 33,
            'endColumn' => 47,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create a new middleware option instance.
 *
 * @param  array  $options
 */',
        'startLine' => 19,
        'endLine' => 22,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Routing',
        'declaringClassName' => 'Illuminate\\Routing\\ControllerMiddlewareOptions',
        'implementingClassName' => 'Illuminate\\Routing\\ControllerMiddlewareOptions',
        'currentClassName' => 'Illuminate\\Routing\\ControllerMiddlewareOptions',
        'aliasName' => NULL,
      ),
      'only' => 
      array (
        'name' => 'only',
        'parameters' => 
        array (
          'methods' => 
          array (
            'name' => 'methods',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 30,
            'endLine' => 30,
            'startColumn' => 26,
            'endColumn' => 33,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Set the controller methods the middleware should apply to.
 *
 * @param  mixed  $methods
 * @return $this
 */',
        'startLine' => 30,
        'endLine' => 35,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Routing',
        'declaringClassName' => 'Illuminate\\Routing\\ControllerMiddlewareOptions',
        'implementingClassName' => 'Illuminate\\Routing\\ControllerMiddlewareOptions',
        'currentClassName' => 'Illuminate\\Routing\\ControllerMiddlewareOptions',
        'aliasName' => NULL,
      ),
      'except' => 
      array (
        'name' => 'except',
        'parameters' => 
        array (
          'methods' => 
          array (
            'name' => 'methods',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 43,
            'endLine' => 43,
            'startColumn' => 28,
            'endColumn' => 35,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Set the controller methods the middleware should exclude.
 *
 * @param  mixed  $methods
 * @return $this
 */',
        'startLine' => 43,
        'endLine' => 48,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Routing',
        'declaringClassName' => 'Illuminate\\Routing\\ControllerMiddlewareOptions',
        'implementingClassName' => 'Illuminate\\Routing\\ControllerMiddlewareOptions',
        'currentClassName' => 'Illuminate\\Routing\\ControllerMiddlewareOptions',
        'aliasName' => NULL,
      ),
    ),
    'traitsData' => 
    array (
      'aliases' => 
      array (
      ),
      'modifiers' => 
      array (
      ),
      'precedences' => 
      array (
      ),
      'hashes' => 
      array (
      ),
    ),
  ),
));