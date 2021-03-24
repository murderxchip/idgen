<?php

/**
 * Class ScdIdGenProxy
 * id生成器代理类，用于id buffer，提供预取和缓冲机制
 * @author qinming@tal.com
 */
class ScdIdGenProxy
{
    //基于某个时间戳来生成id，仅提取其中年月用于生成id
    private $ts      = 0;
    //批量预取范围
    private $batch   = 1000;
    //预取id缓冲区
    private $ids     = [];
    //nextid调用计数器
    private $counter = 0;

    public function __construct($ts = 0)
    {
        $this->ts = $ts;
    }

    /**
     * 预加载id
     * @throws Exception
     */
    private function load()
    {
        $c  = $this->loadCount();
        $id = ScdIdGen::instance()->nextId($c, $this->ts);
        for ($i = 0; $i < $c; $i++) {
            array_push($this->ids, $id + $i);
        }
    }

    /**
     * 预加载策略，根据调用次数计算预加载范围
     * @return int
     */
    private function loadCount()
    {
        $this->counter++;
        switch ($this->counter) {
            case 1:
                return 1;
            case 2:
                return 50;
            case 3:
                return 200;
            default:
                return $this->batch;
        }
    }

    /**
     * 获取下一个id（从缓冲区取），极端情况下拿不到则返回false，业务端可延迟重试
     * @return false|mixed
     */
    public function next()
    {
        if (!count($this->ids)) {
            $try = 3;
            while ($try--) {
                try {
                    $this->load();
                    break;
                } catch (Exception $e) {
                    if (!$try) {
                        return false;
                    }
                    usleep(200000);
                    continue;
                }
            }
        }

        return count($this->ids) ? array_shift($this->ids) : false;
    }
}