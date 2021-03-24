<?php

/**
 * Class ScdIdGen
 * 分布式id生成器
 * 含年月信息
 * @author 7853151@qq.com
 * ID布局
 *          ts 32bit           biz 3bit      year 11bit     month 4bit          incr 14
 * |--------------------------|--------|-----------------|-------------|--------------------------|
 *
 */
class ScdIdGen
{
    static $instance;
    /**
     * @var Redis
     */
    private $redis;

    //年月自增空间上限
    private $maxCount;

    private $biz;

    //时间戳位偏移
    const SHIFT_TS = 32;
    //业务
    const SHIFT_BIZ = 29;
    //年份位偏移
    const SHIFT_Y = 18;
    //月份位偏移
    const SHIFT_M = 14;


    public static function instance($biz = 0)
    {
        if (!self::$instance) {
            self::$instance = new self($biz);
        }

        return self::$instance;
    }

    public function __construct($biz = 0, $redis = null)
    {
        //计算每秒自增最大上限
        $this->maxCount = pow(2, self::SHIFT_M) - 1; //
        $this->biz      = $biz;
        if (!$redis instanceof Redis) {
            //外部redis对象引用失败使用默认对象
            throw new \Exception("redis invalid");
        }
        $this->redis = $redis;
    }

    /**
     * 获取下一个id
     * @param int $n 一次获取多少id，只返回第一个id，建议控制小于1000
     * @param int $ts 指定时间戳则使用指定时间戳的年月生成，否则使用当前时间
     * @return int
     * @throws Exception
     */
    public function nextId($n = 1, $ts = 0)
    {
        $time = time();
        if (!$ts) {
            //不指定时间戳ts，则用自增id空间分配
            $ts = $time;
            list($ts, $tick) = $this->getTick($ts, $n);
            return ($ts << self::SHIFT_TS) | $tick;
        } else {
            $y = date('Y', $ts);
            $m = date('n', $ts);

            $ts = $time;

            list($ts, $tick) = $this->getTsTick($ts, $n);
            return ($ts << self::SHIFT_TS) | $this->biz << self::SHIFT_BIZ | $y << self::SHIFT_Y | $m << self::SHIFT_M | $tick;
        }
    }

    /**
     * 解析id,判断id是否有效
     * @param $id
     * @return false|int[]
     */
    public static function parse($id)
    {
        $ts = $id >> self::SHIFT_TS;
        $b  = ($id - ($ts << self::SHIFT_TS)) >> self::SHIFT_Y;
        $y  = ($id - ($ts << self::SHIFT_TS)) - ($b << self::SHIFT_BIZ) >> self::SHIFT_Y;
        $m  = ($id - ($ts << self::SHIFT_TS) - ($b << self::SHIFT_BIZ) - ($y << self::SHIFT_Y)) >> self::SHIFT_M;
        $n  = ($id - ($ts << self::SHIFT_TS) - ($b << self::SHIFT_BIZ) - ($y << self::SHIFT_Y) - ($m << self::SHIFT_M));
        $year = date('Y', $ts);
        if ($year <= 2010 && $year >= 1970) {
            return [$ts, $b, $y, $m, $n];
        } else {
            return false;
        }
    }

    public static function pack($ts, $b, $y, $m, $n)
    {
        $r = pack('LL', $ts, ($b << self::SHIFT_BIZ) + ($y << self::SHIFT_Y) + ($m << self::SHIFT_M) + $n);
        return $r;
    }

    /**
     * 根据给定时间戳生成tick（自增数）
     * @param int $ts 时间戳
     * @param int $n 预取数量,建议控制上限,如1000
     * @param int $e 缓存延长时长
     * @return array
     * @throws Exception
     */
    private function getTsTick($ts, $n = 1, $e = 0)
    {
        //利用redis incr原子操作实现并发自增计数
        $val = $this->redis->incrBy($this->getTsKey($ts), $n);
        //设置当前时间戳计数器过期, 过期时间为延长时间$e乘延长系数10
        $this->redis->expire($this->getTsKey($ts), 120 + $e * 10);

        $c = intval($val / $this->maxCount);
        //如果自增后溢出最大范围则自动到下一秒空间去分配
        if ($c) {
            //延长时间增加一秒 到下一秒钟
            $e++;
            //设置一个预取尝试上限，如果超出10次则直接异常，外部可根据情况做延迟重试
            if ($e > 10) {
                throw new Exception("ID gen failed");
            }
            return $this->getTsTick($ts + $e, $n, $e);
        }

        return [$ts + $e, $val - ($n - 1)];
    }

    /**
     * 缓存key
     * @param $ts
     * @return string
     */
    private function getTsKey($ts)
    {
        $key = sprintf("idgen-scd-ts-%d", $ts);
        return $key;
    }
}