<?php
/**
 * Created by PhpStorm.
 * User: 白猫
 * Date: 2019/4/18
 * Time: 14:19
 */

namespace GoSwoole\BaseServer\Server\Plugin;


use GoSwoole\BaseServer\Coroutine\Channel;

/**
 * 基础插件，插件类需要继承
 * Class BasePlug
 * @package GoSwoole\BaseServer\Server\Plug
 */
abstract class AbstractPlugin implements PluginInterface
{
    /**
     * @var string
     */
    private $afterClass;
    /**
     * @var PluginInterface
     */
    private $afterPlug;
    /**
     * @var int
     */
    private $orderIndex = 1;

    /**
     * @var Channel
     */
    private $readyChannel;

    public function __construct()
    {
        $this->readyChannel = new Channel();
    }

    /**
     * 在哪个之后
     * @param $className
     */
    public function atAfter($className)
    {
        $this->afterClass = $className;
    }

    /**
     * @return mixed
     */
    public function getAfterClass()
    {
        return $this->afterClass;
    }


    /**
     * @return int
     */
    public function getOrderIndex(): int
    {
        if ($this->afterPlug != null) {
            return $this->orderIndex + $this->afterPlug->getOrderIndex();
        }
        return $this->orderIndex;
    }

    /**
     * @param mixed $afterPlug
     */
    public function setAfterPlug($afterPlug): void
    {
        $this->afterPlug = $afterPlug;
    }

    /**
     * @return Channel
     */
    public function getReadyChannel(): Channel
    {
        return $this->readyChannel;
    }

    public function ready()
    {
        $this->readyChannel->push("ready");
    }
}