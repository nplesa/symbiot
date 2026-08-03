<?php declare(strict_types = 1);

// odsl-/var/www/symbiot.npsoft.ro/app/Http/Controllers/Auth/RegisterController.php-PHPStan\BetterReflection\Reflection\ReflectionClass-App\Http\Controllers\Auth\RegisterController
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.70.0.3-8.4.16-41b08aa736c0731b02abc5b8e6ad7b13bd5d71c4427712efa708ce351a3cda23',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'App\\Http\\Controllers\\Auth\\RegisterController',
        'filename' => '/var/www/symbiot.npsoft.ro/app/Http/Controllers/Auth/RegisterController.php',
      ),
    ),
    'namespace' => 'App\\Http\\Controllers\\Auth',
    'name' => 'App\\Http\\Controllers\\Auth\\RegisterController',
    'shortName' => 'RegisterController',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 11,
    'endLine' => 70,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => 'App\\Http\\Controllers\\Controller',
    'implementsClassNames' => 
    array (
    ),
    'traitClassNames' => 
    array (
      0 => 'Illuminate\\Foundation\\Auth\\RegistersUsers',
    ),
    'immediateConstants' => 
    array (
    ),
    'immediateProperties' => 
    array (
      'redirectTo' => 
      array (
        'declaringClassName' => 'App\\Http\\Controllers\\Auth\\RegisterController',
        'implementingClassName' => 'App\\Http\\Controllers\\Auth\\RegisterController',
        'name' => 'redirectTo',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '\'/home\'',
          'attributes' => 
          array (
            'startLine' => 31,
            'endLine' => 31,
            'startTokenPos' => 57,
            'startFilePos' => 858,
            'endTokenPos' => 57,
            'endFilePos' => 864,
          ),
        ),
        'docComment' => '/**
 * Where to redirect users after registration.
 *
 * @var string
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 31,
        'endLine' => 31,
        'startColumn' => 5,
        'endColumn' => 36,
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
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create a new controller instance.
 *
 * @return void
 */',
        'startLine' => 38,
        'endLine' => 41,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'App\\Http\\Controllers\\Auth',
        'declaringClassName' => 'App\\Http\\Controllers\\Auth\\RegisterController',
        'implementingClassName' => 'App\\Http\\Controllers\\Auth\\RegisterController',
        'currentClassName' => 'App\\Http\\Controllers\\Auth\\RegisterController',
        'aliasName' => NULL,
      ),
      'validator' => 
      array (
        'name' => 'validator',
        'parameters' => 
        array (
          'data' => 
          array (
            'name' => 'data',
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
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 48,
            'endLine' => 48,
            'startColumn' => 74,
            'endColumn' => 84,
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
 * Get a validator for an incoming registration request.
 *
 * @return \\Illuminate\\Contracts\\Validation\\Validator
 */',
        'startLine' => 48,
        'endLine' => 55,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'App\\Http\\Controllers\\Auth',
        'declaringClassName' => 'App\\Http\\Controllers\\Auth\\RegisterController',
        'implementingClassName' => 'App\\Http\\Controllers\\Auth\\RegisterController',
        'currentClassName' => 'App\\Http\\Controllers\\Auth\\RegisterController',
        'aliasName' => NULL,
      ),
      'create' => 
      array (
        'name' => 'create',
        'parameters' => 
        array (
          'data' => 
          array (
            'name' => 'data',
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
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 62,
            'endLine' => 62,
            'startColumn' => 71,
            'endColumn' => 81,
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
 * Create a new user instance after a valid registration.
 *
 * @return User
 */',
        'startLine' => 62,
        'endLine' => 69,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'App\\Http\\Controllers\\Auth',
        'declaringClassName' => 'App\\Http\\Controllers\\Auth\\RegisterController',
        'implementingClassName' => 'App\\Http\\Controllers\\Auth\\RegisterController',
        'currentClassName' => 'App\\Http\\Controllers\\Auth\\RegisterController',
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