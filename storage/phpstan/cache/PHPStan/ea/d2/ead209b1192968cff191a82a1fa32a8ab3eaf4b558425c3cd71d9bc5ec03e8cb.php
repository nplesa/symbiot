<?php declare(strict_types = 1);

// osfsl-/var/www/symbiot.npsoft.ro/vendor/composer/../laravel/ui/auth-backend/RegistersUsers.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Illuminate\Foundation\Auth\RegistersUsers
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6ad6815906ab6294d2ccbf858e4cf7821895b85f649fc32698a8896df141498b-8.4.16-6.70.0.3',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Illuminate\\Foundation\\Auth\\RegistersUsers',
        'filename' => '/var/www/symbiot.npsoft.ro/vendor/composer/../laravel/ui/auth-backend/RegistersUsers.php',
      ),
    ),
    'namespace' => 'Illuminate\\Foundation\\Auth',
    'name' => 'Illuminate\\Foundation\\Auth\\RegistersUsers',
    'shortName' => 'RegistersUsers',
    'isInterface' => false,
    'isTrait' => true,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 10,
    'endLine' => 68,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => NULL,
    'implementsClassNames' => 
    array (
    ),
    'traitClassNames' => 
    array (
      0 => 'Illuminate\\Foundation\\Auth\\RedirectsUsers',
    ),
    'immediateConstants' => 
    array (
    ),
    'immediateProperties' => 
    array (
    ),
    'immediateMethods' => 
    array (
      'showRegistrationForm' => 
      array (
        'name' => 'showRegistrationForm',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Show the application registration form.
 *
 * @return \\Illuminate\\View\\View
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
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\RegistersUsers',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\RegistersUsers',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\RegistersUsers',
        'aliasName' => NULL,
      ),
      'register' => 
      array (
        'name' => 'register',
        'parameters' => 
        array (
          'request' => 
          array (
            'name' => 'request',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Illuminate\\Http\\Request',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 30,
            'endLine' => 30,
            'startColumn' => 30,
            'endColumn' => 45,
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
 * Handle a registration request for the application.
 *
 * @param  \\Illuminate\\Http\\Request  $request
 * @return \\Illuminate\\Http\\RedirectResponse|\\Illuminate\\Http\\JsonResponse
 */',
        'startLine' => 30,
        'endLine' => 45,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\RegistersUsers',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\RegistersUsers',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\RegistersUsers',
        'aliasName' => NULL,
      ),
      'guard' => 
      array (
        'name' => 'guard',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the guard to be used during registration.
 *
 * @return \\Illuminate\\Contracts\\Auth\\StatefulGuard
 */',
        'startLine' => 52,
        'endLine' => 55,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\RegistersUsers',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\RegistersUsers',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\RegistersUsers',
        'aliasName' => NULL,
      ),
      'registered' => 
      array (
        'name' => 'registered',
        'parameters' => 
        array (
          'request' => 
          array (
            'name' => 'request',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Illuminate\\Http\\Request',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 64,
            'endLine' => 64,
            'startColumn' => 35,
            'endColumn' => 50,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'user' => 
          array (
            'name' => 'user',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 64,
            'endLine' => 64,
            'startColumn' => 53,
            'endColumn' => 57,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * The user has been registered.
 *
 * @param  \\Illuminate\\Http\\Request  $request
 * @param  mixed  $user
 * @return mixed
 */',
        'startLine' => 64,
        'endLine' => 67,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\RegistersUsers',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\RegistersUsers',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\RegistersUsers',
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