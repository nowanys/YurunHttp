<?php
namespace Yurun\Util\YurunHttp\Http2;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Yurun\Util\YurunHttp\Attributes;
use Yurun\Util\YurunHttp\Http\Psr7\Uri;

class SwooleClient implements IHttp2Client
{
    /**
     * 主机名
     *
     * @var string
     */
    private $host;

    /**
     * 端口
     *
     * @var int
     */
    private $port;

    /**
     * 是否使用 ssl
     *
     * @var bool
     */
    private $ssl;

    /**
     * Swoole 协程客户端对象
     *
     * @var \Yurun\Util\YurunHttp\Handler\Swoole $handler
     */
    private $handler;

    /**
     * Swoole http2 客户端
     *
     * @var \Swoole\Coroutine\Http2\Client
     */
    private $http2Client;

    /**
     * 接收的频道集合
     *
     * @var \Swoole\Coroutine\Channel[]
     */
    private $recvChannels = [];

    /**
     * 服务端推送数据队列长度
     *
     * @var integer
     */
    private $serverPushQueueLength = 16;

    /**
     * @param string $host
     * @param int $port
     * @param bool $ssl
     * @param \Yurun\Util\YurunHttp\Handler\Swoole $handler
     */
    public function __construct($host, $port, $ssl, $handler = null)
    {
        $this->host = $host;
        $this->port = $port;
        $this->ssl = $ssl;
        if($handler)
        {
            $this->handler = $handler;
        }
        else
        {
            $this->handler = new \Yurun\Util\YurunHttp\Handler\Swoole;
        }
    }

    /**
     * 连接
     *
     * @return bool
     */
    public function connect()
    {
        $client = $this->handler->getHttp2ConnectionManager()->getConnection($this->host, $this->port, $this->ssl);
        if($client)
        {
            $this->http2Client = $client;
            $this->startRecvCo();
            return true;
        }
        else
        {
            return false;
        }
    }

    /**
     * 获取 Http Handler
     *
     * @return \Yurun\Util\YurunHttp\Handler\IHandler
     */ 
    public function getHttpHandler()
    {
        return $this->handler;
    }

    /**
     * 关闭连接
     *
     * @return void
     */
    public function close()
    {
        $this->http2Client = null;
        $this->handler->getHttp2ConnectionManager()->closeConnection($this->host, $this->port, $this->ssl);
        foreach($this->recvChannels as $channel)
        {
            $channel->close();
        }
        $this->recvChannels = [];
    }

    /**
     * 发送数据
     * 成功返回streamId，失败返回false
     *
     * @param \Yurun\Util\YurunHttp\Http\Request $request
     * @param bool $pipeline 默认send方法在发送请求之后，会结束当前的Http2 Stream，启用PIPELINE后，底层会保持stream流，可以多次调用write方法，向服务器发送数据帧，请参考write方法。
     * @param bool $dropRecvResponse 丢弃接收到的响应数据
     * @return int|bool
     */
    public function send($request, $pipeline = false, $dropRecvResponse = false)
    {
        if('2.0' !== $request->getProtocolVersion())
        {
            $request = $request->withProtocolVersion('2.0');
        }
        $uri = $request->getUri();
        if($this->host != $uri->getHost() || $this->port != Uri::getServerPort($uri) || $this->ssl != ('https' === $uri->getScheme()))
        {
            throw new \RuntimeException(sprintf('Current http2 connection instance just support %s://%s:%s, does not support %s', $this->ssl ? 'https' : 'http', $this->host, $this->port, $uri->__toString()));
        }
        $request = $request->withAttribute(Attributes::HTTP2_PIPELINE, $pipeline);
        $this->handler->buildRequest($request, $this->http2Client, $http2Request);
        $streamId = $this->http2Client->send($http2Request);
        if(!$streamId)
        {
            $this->close();
        }
        if(!$dropRecvResponse)
        {
            $this->recvChannels[$streamId] = new Channel(1);
        }
        return $streamId;
    }

    /**
     * 向一个流写入数据帧
     *
     * @param int $streamId
     * @param string $data
     * @param boolean $end 是否关闭流
     * @return bool
     */
    public function write($streamId, $data, $end = false)
    {
        return $this->http2Client->write($streamId, $data, $end);
    }

    /**
     * 关闭一个流
     *
     * @param int $streamId
     * @return bool
     */
    public function end($streamId)
    {
        return $this->http2Client->write($streamId, '', true);
    }

    /**
     * 接收数据
     *
     * @param int|null $streamId 默认不传为 -1 时则监听服务端推送
     * @param double|null $timeout 超时时间，单位：秒。默认为 null 不限制
     * @return \Yurun\Util\YurunHttp\Http\Response|bool
     */
    public function recv($streamId = -1, $timeout = null)
    {
        if(isset($this->recvChannels[$streamId]))
        {
            $channel = $this->recvChannels[$streamId];
        }
        else
        {
            $this->recvChannels[$streamId] = $channel = new Channel(-1 === $streamId ? $this->serverPushQueueLength : 1);
        }
        $swooleResponse = $channel->pop($timeout);
        if(-1 !== $streamId)
        {
            unset($this->recvChannels[$streamId]);
            $channel->close();
        }
        $response = $this->handler->buildHttp2Response($swooleResponse);
        return $response;
    }

    /**
     * 是否已连接
     * 
     * @return boolean
     */
    public function isConnected()
    {
        return null !== $this->http2Client;
    }

    /**
     * 开始接收协程
     * 成功返回协程ID
     *
     * @return int|bool
     */
    private function startRecvCo()
    {
        if(!$this->isConnected())
        {
            return false;
        }
        return Coroutine::create(function(){
            while($this->isConnected())
            {
                $swooleResponse = $this->http2Client->recv();
                if(!$swooleResponse)
                {
                    $this->close();
                    return;
                }
                $streamId = $swooleResponse->streamId;
                if(isset($this->recvChannels[$streamId]) || (0 === $streamId % 2 && isset($this->recvChannels[$streamId = -1])))
                {
                    $this->recvChannels[$streamId]->push($swooleResponse);
                }
            }
        });
    }

    /**
     * Get 主机名
     *
     * @return string
     */ 
    public function getHost()
    {
        return $this->host;
    }

    /**
     * Get 端口
     *
     * @return int
     */ 
    public function getPort()
    {
        return $this->port;
    }

    /**
     * Get 是否使用 ssl
     *
     * @return bool
     */ 
    public function isSSL()
    {
        return $this->ssl;
    }

    /**
     * 获取正在接收的流数量
     *
     * @return int
     */
    public function getRecvingCount()
    {
        return count($this->recvChannels);
    }

    /**
     * Get 服务端推送数据队列长度
     *
     * @return integer
     */ 
    public function getServerPushQueueLength()
    {
        return $this->serverPushQueueLength;
    }

    /**
     * Set 服务端推送数据队列长度
     *
     * @param integer $serverPushQueueLength  服务端推送数据队列长度
     *
     * @return self
     */ 
    public function setServerPushQueueLength($serverPushQueueLength)
    {
        $this->serverPushQueueLength = $serverPushQueueLength;

        return $this;
    }

}