<?php

namespace SwooleTW\Http\Server;

use Exception;
use SwooleTW\Http\Server\Sandbox;
use Swoole\Http\Server as HttpServer;
use Illuminate\Support\Facades\Facade;
use SwooleTW\Http\Websocket\Websocket;
use SwooleTW\Http\Table\CanSwooleTable;
use SwooleTW\Http\Websocket\CanWebsocket;
use Illuminate\Contracts\Container\Container;
use SwooleTW\Http\Websocket\Rooms\RoomContract;
use Swoole\WebSocket\Server as WebSocketServer;
use Illuminate\Contracts\Debug\ExceptionHandler;

class Manager
{
    use CanWebsocket, CanSwooleTable;

    const MAC_OSX = 'Darwin';

    /**
     * @var \Swoole\Http\Server | \Swoole\Websocket\Server
     */
    protected $server;

    /**
     * Container.
     *
     * @var \Illuminate\Contracts\Container\Container
     */
    protected $container;

    /**
     * @var \SwooleTW\Http\Server\Application
     */
    protected $application;

    /**
     * Laravel|Lumen Application.
     *
     * @var \Illuminate\Container\Container
     */
    protected $app;

    /**
     * @var string
     */
    protected $framework;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var boolean
     */
    protected $isSandbox;

    /**
     * @var \SwooleTW\Http\Server\Sandbox
     */
    protected $sandbox;

    /**
     * Server events.
     *
     * @var array
     */
    protected $events = [
        'start', 'shutDown', 'workerStart', 'workerStop', 'packet',
        'bufferFull', 'bufferEmpty', 'task', 'finish', 'pipeMessage',
        'workerError', 'managerStart', 'managerStop', 'request',
    ];

    /**
     * HTTP server manager constructor.
     *
     * @param \Illuminate\Contracts\Container\Container $container
     * @param string $framework
     * @param string $basePath
     */
    public function __construct(Container $container, $framework, $basePath = null)
    {
        $this->container = $container;
        $this->framework = $framework;
        $this->basePath = $basePath;

        $this->initialize();
    }

    /**
     * Run swoole server.
     */
    public function run()
    {
        $this->server->start();
    }

    /**
     * Stop swoole server.
     */
    public function stop()
    {
        $this->server->shutdown();
    }

    /**
     * Initialize.
     */
    protected function initialize()
    {
        $this->setProcessName('manager process');

        $this->setIsSandbox();
        $this->createTables();
        $this->prepareWebsocket();
        $this->createSwooleServer();
        $this->configureSwooleServer();
        $this->setSwooleServerListeners();
    }

    /**
     * Prepare settings if websocket is enabled.
     */
    protected function prepareWebsocket()
    {
        $isWebsocket = $this->container['config']->get('swoole_http.websocket.enabled');
        $parser = $this->container['config']->get('swoole_websocket.parser');

        if ($isWebsocket) {
            array_push($this->events, ...$this->wsEvents);
            $this->isWebsocket = true;
            $this->setParser(new $parser);
            $this->setWebsocketRoom();
        }
    }

    /**
     * Create swoole server.
     */
    protected function createSwooleServer()
    {
        $server = $this->isWebsocket ? WebsocketServer::class : HttpServer::class;
        $host = $this->container['config']->get('swoole_http.server.host');
        $port = $this->container['config']->get('swoole_http.server.port');

        $this->server = new $server($host, $port);
    }

    /**
     * Set swoole server configurations.
     */
    protected function configureSwooleServer()
    {
        $config = $this->container['config']->get('swoole_http.server.options');

        $this->server->set($config);
    }

    /**
     * Set swoole server listeners.
     */
    protected function setSwooleServerListeners()
    {
        foreach ($this->events as $event) {
            $listener = 'on' . ucfirst($event);

            if (method_exists($this, $listener)) {
                $this->server->on($event, [$this, $listener]);
            } else {
                $this->server->on($event, function () use ($event) {
                    $event = sprintf('swoole.%s', $event);

                    $this->container['events']->fire($event, func_get_args());
                });
            }
        }
    }

    /**
     * "onStart" listener.
     */
    public function onStart()
    {
        $this->setProcessName('master process');
        $this->createPidFile();

        $this->container['events']->fire('swoole.start', func_get_args());
    }

    /**
     * "onWorkerStart" listener.
     */
    public function onWorkerStart(HttpServer $server)
    {
        $this->clearCache();
        $this->setProcessName('worker process');

        $this->container['events']->fire('swoole.workerStart', func_get_args());

        // don't init laravel app in task workers
        if ($server->taskworker) {
            return;
        }

        // clear events instance in case of repeated listeners in worker process
        Facade::clearResolvedInstance('events');

        // initialize laravel app
        $this->createApplication();
        $this->setLaravelApp();

        // bind after setting laravel app
        $this->bindToLaravelApp();

        // set application to sandbox environment
        if ($this->isSandbox || $this->isWebsocket) {
            $this->sandbox = Sandbox::make($this->getApplication());
        }

        // load websocket handlers after binding websocket to laravel app
        if ($this->isWebsocket) {
            $this->setWebsocketHandler();
            $this->loadWebsocketRoutes();
        }
    }

    /**
     * "onRequest" listener.
     *
     * @param \Swoole\Http\Request $swooleRequest
     * @param \Swoole\Http\Response $swooleResponse
     */
    public function onRequest($swooleRequest, $swooleResponse)
    {
        $this->app['events']->fire('swoole.request');

        $this->resetOnRequest();

        $application = $this->getApplication();

        try {
            // transform swoole request to illuminate request
            $illuminateRequest = Request::make($swooleRequest)->toIlluminate();

            // use cloned application if sandbox mode is on
            if ($this->isSandbox) {
                $application->getApplication()->instance('request', $illuminateRequest);
                $application = $this->sandbox->getApplication();
                $this->sandbox->enable();
            }

            // bind illuminate request to laravel/lumen
            $application->getApplication()->instance('request', $illuminateRequest);
            Facade::clearResolvedInstance('request');

            // handle request via laravel/lumen's dispatcher
            $illuminateResponse = $application->run($illuminateRequest);
            $response = Response::make($illuminateResponse, $swooleResponse);
            $response->send();

            // disable and recycle sandbox resource
            if ($this->isSandbox) {
                $this->sandbox->disable();
            }
        } catch (Exception $e) {
            try {
                $exceptionResponse = $this->app[ExceptionHandler::class]->render($illuminateRequest, $e);
                $response = Response::make($exceptionResponse, $swooleResponse);
                $response->send();
            } catch (Exception $e) {
                $this->logServerError($e);
            }
        }
    }

    /**
     * Reset on every request.
     */
    protected function resetOnRequest()
    {
        // Reset websocket data
        if ($this->isWebsocket) {
            $this->websocket->reset(true);
        }

        if ($this->isSandbox) {
            return;
        }

        // Reset user-customized providers
        $this->getApplication()->resetProviders();
        // Clear user-customized facades
        $this->getApplication()->clearFacades();
        // Clear user-customized instances
        $this->getApplication()->clearInstances();
    }

    /**
     * Set onTask listener.
     */
    public function onTask(HttpServer $server, $taskId, $fromId, $data)
    {
        $this->container['events']->fire('swoole.task', func_get_args());

        try {
            // push websocket message
            if ($this->isWebsocket
                && array_key_exists('action', $data)
                && $data['action'] === Websocket::PUSH_ACTION) {
                $this->pushMessage($server, $data['data'] ?? []);
            }
        } catch (Exception $e) {
            $this->logServerError($e);
        }
    }

    /**
     * Set onFinish listener.
     */
    public function onFinish(HttpServer $server, $taskId, $data)
    {
        // task worker callback
    }

    /**
     * Set onShutdown listener.
     */
    public function onShutdown()
    {
        $this->removePidFile();

        $this->app['events']->fire('swoole.shutdown', func_get_args());
    }

    /**
     * Create application.
     */
    protected function createApplication()
    {
        return $this->application = Application::make($this->framework, $this->basePath);
    }

    /**
     * Get application.
     *
     * @return \SwooleTW\Http\Server\Application
     */
    protected function getApplication()
    {
        if (! $this->application instanceof Application) {
            $this->createApplication();
        }

        return $this->application;
    }

    /**
     * Set bindings to Laravel app.
     */
    protected function bindToLaravelApp()
    {
        $this->bindSwooleServer();
        $this->bindSwooleTable();

        if ($this->isWebsocket) {
            $this->bindRoom();
            $this->bindWebsocket();
        }
    }

    /**
     * Set isSandbox config.
     */
    protected function setIsSandbox()
    {
        $this->isSandbox = $this->container['config']->get('swoole_http.sandbox_mode', false);
    }

    /**
     * Set Laravel app.
     */
    protected function setLaravelApp()
    {
        $this->app = $this->getApplication()->getApplication();
    }

    /**
     * Bind swoole server to Laravel app container.
     */
    protected function bindSwooleServer()
    {
        $this->app->singleton('swoole.server', function () {
            return $this->server;
        });
    }

    /**
     * Gets pid file path.
     *
     * @return string
     */
    protected function getPidFile()
    {
        return $this->container['config']->get('swoole_http.server.options.pid_file');
    }

    /**
     * Create pid file.
     */
    protected function createPidFile()
    {
        $pidFile = $this->getPidFile();
        $pid = $this->server->master_pid;

        file_put_contents($pidFile, $pid);
    }

    /**
     * Remove pid file.
     */
    protected function removePidFile()
    {
        unlink($this->getPidFile());
    }

    /**
     * Clear APC or OPCache.
     */
    protected function clearCache()
    {
        if (function_exists('apc_clear_cache')) {
            apc_clear_cache();
        }

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * Set process name.
     *
     * @param $process
     */
    protected function setProcessName($process)
    {
        if (PHP_OS === static::MAC_OSX) {
            return;
        }
        $serverName = 'swoole_http_server';
        $appName = $this->container['config']->get('app.name', 'Laravel');

        $name = sprintf('%s: %s for %s', $serverName, $process, $appName);

        swoole_set_process_name($name);
    }

    /**
     * Log server error.
     *
     * @param Exception
     */
    protected function logServerError(Exception $e)
    {
        $this->app[ExceptionHandler::class]->report($e);
    }
}
