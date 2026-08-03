<?php declare(strict_types = 1);

// osfsl-/var/www/symbiot.npsoft.ro/vendor/composer/../laravel/ui/auth-backend/ResetsPasswords.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Illuminate\Foundation\Auth\ResetsPasswords
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-90d49efabb74065df7992289c3d774408f1d2a90fab9dcb0daebe4b0e329d39c-8.4.16-6.70.0.3',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'filename' => '/var/www/symbiot.npsoft.ro/vendor/composer/../laravel/ui/auth-backend/ResetsPasswords.php',
      ),
    ),
    'namespace' => 'Illuminate\\Foundation\\Auth',
    'name' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
    'shortName' => 'ResetsPasswords',
    'isInterface' => false,
    'isTrait' => true,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 15,
    'endLine' => 188,
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
      'showResetForm' => 
      array (
        'name' => 'showResetForm',
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
            'startLine' => 27,
            'endLine' => 27,
            'startColumn' => 35,
            'endColumn' => 50,
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
 * Display the password reset view for the given token.
 *
 * If no token is present, display the link request form.
 *
 * @param  \\Illuminate\\Http\\Request  $request
 * @return \\Illuminate\\Contracts\\View\\Factory|\\Illuminate\\View\\View
 */',
        'startLine' => 27,
        'endLine' => 34,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'aliasName' => NULL,
      ),
      'reset' => 
      array (
        'name' => 'reset',
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
            'startLine' => 42,
            'endLine' => 42,
            'startColumn' => 27,
            'endColumn' => 42,
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
 * Reset the given user\'s password.
 *
 * @param  \\Illuminate\\Http\\Request  $request
 * @return \\Illuminate\\Http\\RedirectResponse|\\Illuminate\\Http\\JsonResponse
 */',
        'startLine' => 42,
        'endLine' => 61,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
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
 * Get the password reset validation rules.
 *
 * @return array
 */',
        'startLine' => 68,
        'endLine' => 75,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
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
 * Get the password reset validation error messages.
 *
 * @return array
 */',
        'startLine' => 82,
        'endLine' => 85,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'aliasName' => NULL,
      ),
      'credentials' => 
      array (
        'name' => 'credentials',
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
            'startLine' => 93,
            'endLine' => 93,
            'startColumn' => 36,
            'endColumn' => 51,
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
 * Get the password reset credentials from the request.
 *
 * @param  \\Illuminate\\Http\\Request  $request
 * @return array
 */',
        'startLine' => 93,
        'endLine' => 98,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'aliasName' => NULL,
      ),
      'resetPassword' => 
      array (
        'name' => 'resetPassword',
        'parameters' => 
        array (
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
            'startLine' => 107,
            'endLine' => 107,
            'startColumn' => 38,
            'endColumn' => 42,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'password' => 
          array (
            'name' => 'password',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 107,
            'endLine' => 107,
            'startColumn' => 45,
            'endColumn' => 53,
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
 * Reset the given user\'s password.
 *
 * @param  \\Illuminate\\Contracts\\Auth\\CanResetPassword  $user
 * @param  string  $password
 * @return void
 */',
        'startLine' => 107,
        'endLine' => 118,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'aliasName' => NULL,
      ),
      'setUserPassword' => 
      array (
        'name' => 'setUserPassword',
        'parameters' => 
        array (
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
            'startLine' => 127,
            'endLine' => 127,
            'startColumn' => 40,
            'endColumn' => 44,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'password' => 
          array (
            'name' => 'password',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 127,
            'endLine' => 127,
            'startColumn' => 47,
            'endColumn' => 55,
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
 * Set the user\'s password.
 *
 * @param  \\Illuminate\\Contracts\\Auth\\CanResetPassword  $user
 * @param  string  $password
 * @return void
 */',
        'startLine' => 127,
        'endLine' => 130,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'aliasName' => NULL,
      ),
      'sendResetResponse' => 
      array (
        'name' => 'sendResetResponse',
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
            'startLine' => 139,
            'endLine' => 139,
            'startColumn' => 42,
            'endColumn' => 57,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'response' => 
          array (
            'name' => 'response',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 139,
            'endLine' => 139,
            'startColumn' => 60,
            'endColumn' => 68,
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
 * Get the response for a successful password reset.
 *
 * @param  \\Illuminate\\Http\\Request  $request
 * @param  string  $response
 * @return \\Illuminate\\Http\\RedirectResponse|\\Illuminate\\Http\\JsonResponse
 */',
        'startLine' => 139,
        'endLine' => 147,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'aliasName' => NULL,
      ),
      'sendResetFailedResponse' => 
      array (
        'name' => 'sendResetFailedResponse',
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
            'startLine' => 156,
            'endLine' => 156,
            'startColumn' => 48,
            'endColumn' => 63,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'response' => 
          array (
            'name' => 'response',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 156,
            'endLine' => 156,
            'startColumn' => 66,
            'endColumn' => 74,
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
 * Get the response for a failed password reset.
 *
 * @param  \\Illuminate\\Http\\Request  $request
 * @param  string  $response
 * @return \\Illuminate\\Http\\RedirectResponse|\\Illuminate\\Http\\JsonResponse
 */',
        'startLine' => 156,
        'endLine' => 167,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'aliasName' => NULL,
      ),
      'broker' => 
      array (
        'name' => 'broker',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the broker to be used during password reset.
 *
 * @return \\Illuminate\\Contracts\\Auth\\PasswordBroker
 */',
        'startLine' => 174,
        'endLine' => 177,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
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
 * Get the guard to be used during password reset.
 *
 * @return \\Illuminate\\Contracts\\Auth\\StatefulGuard
 */',
        'startLine' => 184,
        'endLine' => 187,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\ResetsPasswords',
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