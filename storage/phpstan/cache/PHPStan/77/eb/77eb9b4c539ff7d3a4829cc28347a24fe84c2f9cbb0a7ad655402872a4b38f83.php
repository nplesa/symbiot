<?php declare(strict_types = 1);

// osfsl-/var/www/symbiot.npsoft.ro/vendor/composer/../laravel/ui/auth-backend/VerifiesEmails.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Illuminate\Foundation\Auth\VerifiesEmails
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-087828baad7ba5d37096575d13b37dbf4a9283bf2fbdeebb2f86f36b4782b73d-8.4.16-6.70.0.3',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Illuminate\\Foundation\\Auth\\VerifiesEmails',
        'filename' => '/var/www/symbiot.npsoft.ro/vendor/composer/../laravel/ui/auth-backend/VerifiesEmails.php',
      ),
    ),
    'namespace' => 'Illuminate\\Foundation\\Auth',
    'name' => 'Illuminate\\Foundation\\Auth\\VerifiesEmails',
    'shortName' => 'VerifiesEmails',
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
    'endLine' => 95,
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
      'show' => 
      array (
        'name' => 'show',
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
            'startLine' => 20,
            'endLine' => 20,
            'startColumn' => 26,
            'endColumn' => 41,
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
 * Show the email verification notice.
 *
 * @param  \\Illuminate\\Http\\Request  $request
 * @return \\Illuminate\\Http\\RedirectResponse|\\Illuminate\\View\\View
 */',
        'startLine' => 20,
        'endLine' => 25,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\VerifiesEmails',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\VerifiesEmails',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\VerifiesEmails',
        'aliasName' => NULL,
      ),
      'verify' => 
      array (
        'name' => 'verify',
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
            'startLine' => 35,
            'endLine' => 35,
            'startColumn' => 28,
            'endColumn' => 43,
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
 * Mark the authenticated user\'s email address as verified.
 *
 * @param  \\Illuminate\\Http\\Request  $request
 * @return \\Illuminate\\Http\\JsonResponse|\\Illuminate\\Http\\RedirectResponse
 *
 * @throws \\Illuminate\\Auth\\Access\\AuthorizationException
 */',
        'startLine' => 35,
        'endLine' => 62,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\VerifiesEmails',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\VerifiesEmails',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\VerifiesEmails',
        'aliasName' => NULL,
      ),
      'verified' => 
      array (
        'name' => 'verified',
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
            'startLine' => 70,
            'endLine' => 70,
            'startColumn' => 33,
            'endColumn' => 48,
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
 * The user has been verified.
 *
 * @param  \\Illuminate\\Http\\Request  $request
 * @return mixed
 */',
        'startLine' => 70,
        'endLine' => 73,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\VerifiesEmails',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\VerifiesEmails',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\VerifiesEmails',
        'aliasName' => NULL,
      ),
      'resend' => 
      array (
        'name' => 'resend',
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
            'startLine' => 81,
            'endLine' => 81,
            'startColumn' => 28,
            'endColumn' => 43,
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
 * Resend the email verification notification.
 *
 * @param  \\Illuminate\\Http\\Request  $request
 * @return \\Illuminate\\Http\\JsonResponse|\\Illuminate\\Http\\RedirectResponse
 */',
        'startLine' => 81,
        'endLine' => 94,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Foundation\\Auth',
        'declaringClassName' => 'Illuminate\\Foundation\\Auth\\VerifiesEmails',
        'implementingClassName' => 'Illuminate\\Foundation\\Auth\\VerifiesEmails',
        'currentClassName' => 'Illuminate\\Foundation\\Auth\\VerifiesEmails',
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