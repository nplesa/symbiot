<?php declare(strict_types = 1);

// osfsl-/var/www/symbiot.npsoft.ro/vendor/composer/../laravel/framework/src/Illuminate/Session/SessionManager.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Illuminate\Session\SessionManager
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-c3ba1ccb3558fd5473545bd1a397d40805e07331e6f11695840e1407e5a79ad4-8.4.16-6.70.0.3',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Illuminate\\Session\\SessionManager',
        'filename' => '/var/www/symbiot.npsoft.ro/vendor/composer/../laravel/framework/src/Illuminate/Session/SessionManager.php',
      ),
    ),
    'namespace' => 'Illuminate\\Session',
    'name' => 'Illuminate\\Session\\SessionManager',
    'shortName' => 'SessionManager',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => '/**
 * @mixin \\Illuminate\\Session\\Store
 */',
    'attributes' => 
    array (
    ),
    'startLine' => 12,
    'endLine' => 295,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => 'Illuminate\\Support\\Manager',
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
      'callCustomCreator' => 
      array (
        'name' => 'callCustomCreator',
        'parameters' => 
        array (
          'driver' => 
          array (
            'name' => 'driver',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 20,
            'endLine' => 20,
            'startColumn' => 42,
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
 * Call a custom driver creator.
 *
 * @param  string  $driver
 * @return \\Illuminate\\Session\\Store
 */',
        'startLine' => 20,
        'endLine' => 23,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'createNullDriver' => 
      array (
        'name' => 'createNullDriver',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create an instance of the "null" session driver.
 *
 * @return \\Illuminate\\Session\\Store
 */',
        'startLine' => 30,
        'endLine' => 33,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'createArrayDriver' => 
      array (
        'name' => 'createArrayDriver',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create an instance of the "array" session driver.
 *
 * @return \\Illuminate\\Session\\Store
 */',
        'startLine' => 40,
        'endLine' => 45,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'createCookieDriver' => 
      array (
        'name' => 'createCookieDriver',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create an instance of the "cookie" session driver.
 *
 * @return \\Illuminate\\Session\\Store
 */',
        'startLine' => 52,
        'endLine' => 59,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'createFileDriver' => 
      array (
        'name' => 'createFileDriver',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create an instance of the file session driver.
 *
 * @return \\Illuminate\\Session\\Store
 */',
        'startLine' => 66,
        'endLine' => 69,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'createNativeDriver' => 
      array (
        'name' => 'createNativeDriver',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create an instance of the file session driver.
 *
 * @return \\Illuminate\\Session\\Store
 */',
        'startLine' => 76,
        'endLine' => 83,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'createDatabaseDriver' => 
      array (
        'name' => 'createDatabaseDriver',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create an instance of the database session driver.
 *
 * @return \\Illuminate\\Session\\Store
 */',
        'startLine' => 90,
        'endLine' => 99,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'getDatabaseConnection' => 
      array (
        'name' => 'getDatabaseConnection',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the database connection for the database driver.
 *
 * @return \\Illuminate\\Database\\Connection
 */',
        'startLine' => 106,
        'endLine' => 111,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'createApcDriver' => 
      array (
        'name' => 'createApcDriver',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create an instance of the APC session driver.
 *
 * @return \\Illuminate\\Session\\Store
 */',
        'startLine' => 118,
        'endLine' => 121,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'createMemcachedDriver' => 
      array (
        'name' => 'createMemcachedDriver',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create an instance of the Memcached session driver.
 *
 * @return \\Illuminate\\Session\\Store
 */',
        'startLine' => 128,
        'endLine' => 131,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'createRedisDriver' => 
      array (
        'name' => 'createRedisDriver',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create an instance of the Redis session driver.
 *
 * @return \\Illuminate\\Session\\Store
 */',
        'startLine' => 138,
        'endLine' => 151,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'createDynamodbDriver' => 
      array (
        'name' => 'createDynamodbDriver',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create an instance of the DynamoDB session driver.
 *
 * @return \\Illuminate\\Session\\Store
 */',
        'startLine' => 158,
        'endLine' => 161,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'createCacheBased' => 
      array (
        'name' => 'createCacheBased',
        'parameters' => 
        array (
          'driver' => 
          array (
            'name' => 'driver',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 169,
            'endLine' => 169,
            'startColumn' => 41,
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
 * Create an instance of a cache driven driver.
 *
 * @param  string  $driver
 * @return \\Illuminate\\Session\\Store
 */',
        'startLine' => 169,
        'endLine' => 172,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'createCacheHandler' => 
      array (
        'name' => 'createCacheHandler',
        'parameters' => 
        array (
          'driver' => 
          array (
            'name' => 'driver',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 180,
            'endLine' => 180,
            'startColumn' => 43,
            'endColumn' => 49,
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
 * Create the cache based session handler instance.
 *
 * @param  string  $driver
 * @return \\Illuminate\\Session\\CacheBasedSessionHandler
 */',
        'startLine' => 180,
        'endLine' => 188,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'buildSession' => 
      array (
        'name' => 'buildSession',
        'parameters' => 
        array (
          'handler' => 
          array (
            'name' => 'handler',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 196,
            'endLine' => 196,
            'startColumn' => 37,
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
 * Build the session instance.
 *
 * @param  \\SessionHandlerInterface  $handler
 * @return \\Illuminate\\Session\\Store
 */',
        'startLine' => 196,
        'endLine' => 206,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'buildEncryptedSession' => 
      array (
        'name' => 'buildEncryptedSession',
        'parameters' => 
        array (
          'handler' => 
          array (
            'name' => 'handler',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 214,
            'endLine' => 214,
            'startColumn' => 46,
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
 * Build the encrypted session instance.
 *
 * @param  \\SessionHandlerInterface  $handler
 * @return \\Illuminate\\Session\\EncryptedStore
 */',
        'startLine' => 214,
        'endLine' => 223,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'shouldBlock' => 
      array (
        'name' => 'shouldBlock',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Determine if requests for the same session should wait for each to finish before executing.
 *
 * @return bool
 */',
        'startLine' => 230,
        'endLine' => 233,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'blockDriver' => 
      array (
        'name' => 'blockDriver',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the name of the cache store / driver that should be used to acquire session locks.
 *
 * @return string|null
 */',
        'startLine' => 240,
        'endLine' => 243,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'defaultRouteBlockLockSeconds' => 
      array (
        'name' => 'defaultRouteBlockLockSeconds',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the maximum number of seconds the session lock should be held for.
 *
 * @return int
 */',
        'startLine' => 250,
        'endLine' => 253,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'defaultRouteBlockWaitSeconds' => 
      array (
        'name' => 'defaultRouteBlockWaitSeconds',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the maximum number of seconds to wait while attempting to acquire a route block session lock.
 *
 * @return int
 */',
        'startLine' => 260,
        'endLine' => 263,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'getSessionConfig' => 
      array (
        'name' => 'getSessionConfig',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the session configuration.
 *
 * @return array
 */',
        'startLine' => 270,
        'endLine' => 273,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'getDefaultDriver' => 
      array (
        'name' => 'getDefaultDriver',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the default session driver name.
 *
 * @return string|null
 */',
        'startLine' => 280,
        'endLine' => 283,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
        'aliasName' => NULL,
      ),
      'setDefaultDriver' => 
      array (
        'name' => 'setDefaultDriver',
        'parameters' => 
        array (
          'name' => 
          array (
            'name' => 'name',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 291,
            'endLine' => 291,
            'startColumn' => 38,
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
 * Set the default session driver name.
 *
 * @param  \\UnitEnum|string  $name
 * @return void
 */',
        'startLine' => 291,
        'endLine' => 294,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Session',
        'declaringClassName' => 'Illuminate\\Session\\SessionManager',
        'implementingClassName' => 'Illuminate\\Session\\SessionManager',
        'currentClassName' => 'Illuminate\\Session\\SessionManager',
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