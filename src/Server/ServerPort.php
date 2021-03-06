<?php
/**
 * Created by PhpStorm.
 * User: 白猫
 * Date: 2019/4/15
 * Time: 16:47
 */

namespace GoSwoole\BaseServer\Server;


use GoSwoole\BaseServer\Server\Beans\Request;
use GoSwoole\BaseServer\Server\Beans\Response;
use GoSwoole\BaseServer\Server\Beans\WebSocketCloseFrame;
use GoSwoole\BaseServer\Server\Beans\WebSocketFrame;
use GoSwoole\BaseServer\Server\Config\PortConfig;

/**
 * ServerPort 端口类
 * Class ServerPort
 * @package GoSwoole\BaseServer\Server
 */
abstract class ServerPort
{
    /**
     * @var PortConfig
     */
    private $portConfig;
    /**
     * @var Server
     */
    private $server;
    /**
     * @var Context
     */
    protected $context;
    /**
     * swoole的port对象
     * @var \Swoole\Server\Port
     */
    private $swoolePort;

    public function __construct(Server $server, PortConfig $portConfig)
    {
        $this->portConfig = $portConfig;
        $this->server = $server;
        $this->context = $this->server->getContext();
    }

    /**
     * @return PortConfig
     */
    public function getPortConfig(): PortConfig
    {
        return $this->portConfig;
    }

    /**
     * @return mixed
     */
    public function getSwoolePort()
    {
        return $this->swoolePort;
    }

    /**
     * 创建端口
     * @throws exception\ConfigException
     */
    public function create(): void
    {
        if ($this->server->getMainPort() == $this) {
            //端口已经被swoole创建了，直接获取port实例
            $this->swoolePort = $this->server->getServer()->ports[0];
            //监听者是server
            $listening = $this->server->getServer();
        } else {
            $configData = $this->getPortConfig()->buildConfig();
            $this->swoolePort = $this->server->getServer()->listen($this->getPortConfig()->getHost(),
                $this->getPortConfig()->getPort(),
                $this->getPortConfig()->getSwooleSockType());
            $this->swoolePort->set($configData);
            //监听者是端口
            $listening = $this->swoolePort;
        }
        //配置回调
        //TCP
        if ($this->isTcp()) {
            $listening->on("connect", [$this, "_onConnect"]);
            $listening->on("close", [$this, "_onClose"]);
            $listening->on("receive", [$this, "_onReceive"]);
            $listening->on("bufferFull", [$this, "_onBufferFull"]);
            $listening->on("bufferEmpty", [$this, "_onBufferEmpty"]);
        }
        //UDP
        if ($this->isUDP()) {
            $listening->on("packet", [$this, "_onPacket"]);
        }
        //HTTP
        if ($this->isHttp()) {
            $listening->on("request", [$this, "_onRequest"]);
        }
        //WebSocket
        if ($this->isWebSocket()) {
            $listening->on("message", [$this, "_onMessage"]);
            $listening->on("open", [$this, "_onOpen"]);
            if ($this->getPortConfig()->isCustomHandShake()) {
                $listening->on("handshake", [$this, "_onHandshake"]);
            }
        }
    }

    public function _onConnect($server, int $fd, int $reactorId)
    {
        $this->onTcpConnect($fd, $reactorId);
    }

    public function _onClose($server, int $fd, int $reactorId)
    {
        $this->onTcpClose($fd, $reactorId);
    }

    public function _onReceive($server, int $fd, int $reactorId, string $data)
    {
        $this->onTcpReceive($fd, $reactorId, $data);
    }

    public function _onBufferFull($server, int $fd)
    {
        $this->onTcpBufferFull($fd);
    }

    public function _onBufferEmpty($server, int $fd)
    {
        $this->onTcpBufferEmpty($fd);
    }

    public function _onPacket($server, string $data, array $client_info)
    {
        $this->onUdpPacket($data, $client_info);
    }

    public function _onRequest($request, $response)
    {
        $this->onHttpRequest(new Request($request), new Response($response));
    }

    public function _onMessage($server, $frame)
    {
        if (isset($frame['code'])) {
            //是个CloseFrame
            $this->onWsMessage(new WebSocketCloseFrame($frame));
        } else {
            $this->onWsMessage(new WebSocketFrame($frame));
        }
    }

    public function _onOpen($server, $request)
    {
        $this->onWsOpen(new Request($request));
    }

    public function _onHandshake($request, $response)
    {
        $success = $this->onWsPassCustomHandshake(new Request($request));
        if (!$success) return false;
        // websocket握手连接算法验证
        $secWebSocketKey = $request->header['sec-websocket-key'];
        $patten = '#^[+/0-9A-Za-z]{21}[AQgw]==$#';
        if (0 === preg_match($patten, $secWebSocketKey) || 16 !== strlen(base64_decode($secWebSocketKey))) {
            $response->end();
            return false;
        }
        $key = base64_encode(sha1(
            $request->header['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
            true
        ));
        $headers = [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Accept' => $key,
            'Sec-WebSocket-Version' => '13',
        ];
        if (isset($request->header['sec-websocket-protocol'])) {
            $headers['Sec-WebSocket-Protocol'] = $request->header['sec-websocket-protocol'];
        }
        foreach ($headers as $key => $val) {
            $response->header($key, $val);
        }
        $response->status(101);
        $response->end();
        $this->server->defer(function () use ($request) {
            $this->onOpen($request);
        });
    }

    public abstract function onTcpConnect(int $fd, int $reactorId);

    public abstract function onTcpClose(int $fd, int $reactorId);

    public abstract function onTcpReceive(int $fd, int $reactorId, string $data);

    public abstract function onTcpBufferFull(int $fd);

    public abstract function onTcpBufferEmpty(int $fd);

    public abstract function onUdpPacket(string $data, array $client_info);

    public abstract function onHttpRequest(Request $request, Response $response);

    public abstract function onWsMessage(WebSocketFrame $frame);

    public abstract function onWsOpen(Request $request);

    public abstract function onWsPassCustomHandshake(Request $request): bool;

    /**
     * 是否是TCP
     * @return bool
     */
    public function isTcp(): bool
    {
        if ($this->isHttp()) return false;
        if ($this->isWebSocket()) return false;
        if ($this->getPortConfig()->getSockType() == PortConfig::SWOOLE_SOCK_TCP ||
            $this->getPortConfig()->getSockType() == PortConfig::SWOOLE_SOCK_TCP6) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 是否是UDP
     * @return bool
     */
    public function isUDP(): bool
    {
        if ($this->getPortConfig()->getSockType() == PortConfig::SWOOLE_SOCK_UDP ||
            $this->getPortConfig()->getSockType() == PortConfig::SWOOLE_SOCK_UDP6) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 是否是HTTP
     * @return bool
     */
    public function isHttp(): bool
    {
        return $this->getPortConfig()->isOpenHttpProtocol() || $this->getPortConfig()->isOpenWebsocketProtocol();
    }

    /**
     * 是否是WebSocket
     * @return bool
     */
    public function isWebSocket(): bool
    {
        return $this->getPortConfig()->isOpenWebsocketProtocol();
    }

}