<?php declare(strict_types = 1);

// osfsl-/var/www/symbiot.npsoft.ro/vendor/composer/../laravel/ui/auth-backend/SendsPasswordResetEmails.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Illuminate\Foundation\Auth\SendsPasswordResetEmails
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-f03a623d942ed5f3dffc1029392cee46ea96234321c43d72e6a8d513e14ab24a-8.4.16-6.70.0.3',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
        'filename' => '/var/www/symbiot.npsoft.ro/vendor/composer/../laravel/ui/auth-backend/SendsPasswordResetEmails.php',
      ),
    ),
    'namespace' => 'Illuminate\\Foundation\\Auth',
    'name' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
    'shortName' => 'SendsPasswordResetEmails',
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
    'endLine' => 111,
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
    ),
    'immediateMethods' => 
    array (
      'showLinkRequestForm' => 
      array (
        'name' => 'showLinkRequestForm',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Display the form to request a password reset link.
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
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
        'aliasName' => NULL,
      ),
      'sendResetLinkEmail' => 
      array (
        'name' => 'sendResetLinkEmail',
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
            'startColumn' => 40,
            'endColumn' => 55,
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
 * Send a reset link to the given user.
 *
 * @param  \\Illuminate\\Http\\Request  $request
 * @return \\Illuminate\\Http\\RedirectResponse|\\Illuminate\\Http\\JsonResponse
 */',
        'startLine' => 28,
        'endLine' => 42,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
        'aliasName' => NULL,
      ),
      'validateEmail' => 
      array (
        'name' => 'validateEmail',
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
            'startLine' => 50,
            'endLine' => 50,
            'startColumn' => 38,
            'endColumn' => 53,
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
 * Validate the email for the given request.
 *
 * @param  \\Illuminate\\Http\\Request  $request
 * @return void
 */',
        'startLine' => 50,
        'endLine' => 53,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
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
            'startLine' => 61,
            'endLine' => 61,
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
 * Get the needed authentication credentials from the request.
 *
 * @param  \\Illuminate\\Http\\Request  $request
 * @return array
 */',
        'startLine' => 61,
        'endLine' => 64,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
        'aliasName' => NULL,
      ),
      'sendResetLinkResponse' => 
      array (
        'name' => 'sendResetLinkResponse',
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
            'startLine' => 73,
            'endLine' => 73,
            'startColumn' => 46,
            'endColumn' => 61,
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
            'startLine' => 73,
            'endLine' => 73,
            'startColumn' => 64,
            'endColumn' => 72,
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
 * Get the response for a successful password reset link.
 *
 * @param  \\Illuminate\\Http\\Request  $request
 * @param  string  $response
 * @return \\Illuminate\\Http\\RedirectResponse|\\Illuminate\\Http\\JsonResponse
 */',
        'startLine' => 73,
        'endLine' => 78,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
        'aliasName' => NULL,
      ),
      'sendResetLinkFailedResponse' => 
      array (
        'name' => 'sendResetLinkFailedResponse',
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
            'startLine' => 89,
            'endLine' => 89,
            'startColumn' => 52,
            'endColumn' => 67,
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
            'startLine' => 89,
            'endLine' => 89,
            'startColumn' => 70,
            'endColumn' => 78,
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
 * Get the response for a failed password reset link.
 *
 * @param  \\Illuminate\\Http\\Request  $request
 * @param  string  $response
 * @return \\Illuminate\\Http\\RedirectResponse
 *
 * @throws \\Illuminate\\Validation\\ValidationException
 */',
        'startLine' => 89,
        'endLine' => 100,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
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
        'startLine' => 107,
        'endLine' => 110,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\SendsPasswordResetEmails',
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