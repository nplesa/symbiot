<?php declare(strict_types = 1);

// osfsl-/var/www/symbiot.npsoft.ro/vendor/composer/../laravel/ui/auth-backend/ConfirmsPasswords.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Illuminate\Foundation\Auth\ConfirmsPasswords
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-4ac60d2939c1dae931950776df0fafe0bd329b5aa205f4e5836dcd5456e76e32-8.4.16-6.70.0.3',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Illuminate\\Foundation\\Auth\\ConfirmsPasswords',
        'filename' => '/var/www/symbiot.npsoft.ro/vendor/composer/../laravel/ui/auth-backend/ConfirmsPasswords.php',
      ),
    ),
    'namespace' => 'Illuminate\\Foundation\\Auth',
    'name' => 'Illuminate\\Foundation\\Auth\\ConfirmsPasswords',
    'shortName' => 'ConfirmsPasswords',
    'isInterface' => false,
    'isTrait' => true,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 8,
    'endLine' => 71,
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
      'showConfirmForm' => 
      array (
        'name' => 'showConfirmForm',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Display the password confirmation view.
 *
 * @return \\Illuminate\\View\\View
 */',
        'startLine' => 17,
        'endLine' => 20,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\ConfirmsPasswords',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\ConfirmsPasswords',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\ConfirmsPasswords',
        'aliasName' => NULL,
      ),
      'confirm' => 
      array (
        'name' => 'confirm',
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
            'startLine' => 28,
            'endLine' => 28,
            'startColumn' => 29,
            'endColumn' => 44,
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
 * Confirm the given user\'s password.
 *
 * @param  \\Illuminate\\Http\\Request  $request
 * @return \\Illuminate\\Http\\RedirectResponse|\\Illuminate\\Http\\JsonResponse
 */',
        'startLine' => 28,
        'endLine' => 37,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\ConfirmsPasswords',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\ConfirmsPasswords',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\ConfirmsPasswords',
        'aliasName' => NULL,
      ),
      'resetPasswordConfirmationTimeout' => 
      array (
        'name' => 'resetPasswordConfirmationTimeout',
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
            'startLine' => 45,
            'endLine' => 45,
            'startColumn' => 57,
            'endColumn' => 72,
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
 * Reset the password confirmation timeout.
 *
 * @param  \\Illuminate\\Http\\Request  $request
 * @return void
 */',
        'startLine' => 45,
        'endLine' => 48,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\ConfirmsPasswords',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\ConfirmsPasswords',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\ConfirmsPasswords',
        'aliasName' => NULL,
      ),
      'rules' => 
      array (
        'name' => 'rules',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the password confirmation validation rules.
 *
 * @return array
 */',
        'startLine' => 55,
        'endLine' => 60,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\ConfirmsPasswords',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\ConfirmsPasswords',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\ConfirmsPasswords',
        'aliasName' => NULL,
      ),
      'validationErrorMessages' => 
      array (
        'name' => 'validationErrorMessages',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the password confirmation validation error messages.
 *
 * @return array
 */',
        'startLine' => 67,
        'endLine' => 70,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\ConfirmsPasswords',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\ConfirmsPasswords',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\ConfirmsPasswords',
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