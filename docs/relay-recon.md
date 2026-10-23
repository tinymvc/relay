# Relay Recon Findings (Phase 0)

## 1. Console command registration and discovery
- **File paths**: `tinycore/src/Console/Commands.php`, `tinycore/src/Foundation/Providers/ConsoleServiceProvider.php`, `tinycore/src/Foundation/Console/PrimaryCommandsHandler.php`, `tinycore/src/Console/Prompt.php`.
- **Finding**: Commands are registered with `$commands->addCommand('name', $callback)->description('...')` or via `command($name, $callback, $description)`; arguments are parsed by `Prompt::parseArguments()` into options (`$args['key']`) and positional args (`$args['_args']`); output uses `Prompt` helpers (`info`, `message`, `line`, `error`, `status`); exit codes return standard integers.

## 2. Queue worker loop, signals, memory limit, deploy restart
- **File paths**: `tinycore/src/Queue/Queue.php:127-220`, `tinycore/src/Foundation/Console/PrimaryCommandsHandler.php:184-194`.
- **Finding**: `queue:work` runs a `do { ... } while(true)` loop checking elapsed time against timeout; signals and workers in production are managed via Supervisor; for `relay:start`, ReactPHP loop handles pcntl signals (guarded by `function_exists('pcntl_signal')`), a periodic timer checks memory limit (`memory_get_usage(true) >= limit`), and deploy restarts use `supervisorctl restart relay`.

## 3. Configuration and environment loading
- **File paths**: `tinycore/src/DotEnv.php:73-125`, `tinycore/src/Foundation/Application.php:324-334`, `skeleton/config/queue.php`.
- **Finding**: `DotEnv::bootstrap()` loads `.env` variables and `DotEnv::discoverConfig()` discovers all `.php` files in `config/` into `config('relay.*')`; module default configs ship in `config/relay.php` (merged or copied).

## 4. Container registration, providers, and facades
- **File paths**: `tinycore/src/Foundation/Application.php:108-157`, `tinycore/src/Foundation/Providers/ServiceProvider.php`, `tinycore/src/Facades/Facade.php`, `tinycore/src/Facades/Queue.php`.
- **Finding**: Singletons register via `$this->singleton(Class::class)` and `$this->alias('name', Class::class)`; providers extend `ServiceProvider` with `register()` and `boot()`; facades extend `Spark\Facades\Facade` and implement `getFacadeAccessor()`.

## 5. Logging facade and CLI error handling
- **File paths**: `tinycore/src/Tracer.php:76-154`, `tinycore/src/Facades/Log.php`, `tinycore/src/Foundation/helpers.php:2260-2270`.
- **Finding**: `Spark\Facades\Log` and `logger()` log through `Spark\Tracer`; in CLI debug mode warnings are printed without exiting, but uncaught `Throwable`s in `Tracer::handleException` call `exit(0)`; therefore, the daemon must wrap all callbacks and requests in `try/catch` and log via `Log::error()` so errors never terminate the loop.

## 6. Composer and dependencies
- **File paths**: `tinycore/composer.json`, `relay/composer.json`.
- **Finding**: PHP version floor is `>=8.2`; PSR-4 maps `Spark\` in tinycore and `Spark\Relay\` + `Relay\` in relay; `ext-pcntl` is optional/suggested (unavailable on Windows) and guarded with `function_exists('pcntl_signal')`.

## 7. Test runner
- **File paths**: `tinycore/src/Testing/Runner.php`, `skeleton/test`, `tinycore/src/Testing/TestCase.php`.
- **Finding**: `Spark\Testing\Runner` discovers `*Test.php` running public `test*` methods on classes extending `Spark\Testing\TestCase`; tests are filtered with `--filter=Relay`; subprocesses (Node, curl) can be spawned for E2E tests.

## 8. Auth module, CSRF, and responses
- **File paths**: `tinycore/src/Http/Auth.php`, `tinycore/src/Foundation/Http/Middlewares/CsrfProtection.php`, `tinycore/src/Foundation/helpers.php:225-228`, `tinycore/src/Http/Response.php`.
- **Finding**: Current user is retrieved via `auth()->user()`, `auth()->id()`, or `user()`; CSRF validates `_token` or `X-CSRF-TOKEN` against `session('csrf_token')`; broadcasting auth routes are registered with `Route::post('/broadcasting/auth', ...)`; Pusher SDK auth JSON must be returned with `response($json, 200, ['Content-Type' => 'application/json'])` to avoid double encoding.

## Confirmed Architecture and Layout
- Package: `tinymvc/relay` in `relay/`
- Namespaces: `Spark\Relay\` (primary) and `Relay\` (alias)
- Daemon files:
  - `src/Server.php`
  - `src/Connection.php`
  - `src/Channels.php`
  - `src/Protocol.php`
  - `src/Signature.php`
  - `src/App.php`
  - `src/Api.php`
  - `src/Console/StartCommand.php`
- Application & Backend files:
  - `src/Relay.php`
  - `src/ChannelAuth.php`
  - `src/RelayServiceProvider.php`
  - `src/Facades/Relay.php`
- Config: `config/relay.php`
- Tests: `tests/Unit/*Test.php` and `tests/e2e/*Test.php`


## Phase 1: Transport Spike Results
The raw `React\Socket\SocketServer` with `GuzzleHttp\Psr7\Message::parseRequest`, `Ratchet\RFC6455\Handshake\ServerNegotiator`, and `Ratchet\RFC6455\Messaging\MessageBuffer` was verified and selected.

### Gate Results:
- **(a) Node script real pusher-js connected & received socket_id**: PASSED (`12345.67890`)
- **(b) Stayed connected longer than 2 x activity_timeout (8s > 6s)**: PASSED (ping/pong active)
- **(c) Client disconnect detected server-side**: PASSED (`[Connection] Closed` emitted)
- **(d) Real SDK trigger() and curl POST > 2KB with Expect: 100-continue**: PASSED (HTTP 200, `{}` response)
- **(e) Server-initiated close code 4200 causes pusher-js immediate reconnect**: PASSED (client reconnected in 1ms)
