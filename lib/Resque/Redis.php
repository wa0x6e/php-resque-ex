<?php

if (class_exists('Redis')) {
    class RedisApi extends Redis
    {
        public function __construct($host, $port, $timeout = 5, $password = null)
        {
            parent::__construct();

            $this->host = $host;
            $this->port = $port;
            $this->timeout = $timeout;
            $this->password = $password;

            $this->establishConnection();
        }

        function establishConnection()
        {
            $this->pconnect($this->host, (int) $this->port, (int) $this->timeout, getmypid());
            if ($this->password !== null) {
                $this->auth($this->password);
            }
        }
    }
} else {
    // Third- party apps may have already loaded Resident from elsewhere
    // so lets be careful.
    if (!class_exists('Redisent', false)) {
        require_once dirname(__FILE__) . '/../Redisent/Redisent.php';
    }

    /**
     * Extended Redisent class used by Resque for all communication with
     * redis.
     *
     * @package        Resque/Redis
     * @author        Chris Boulton <chris.boulton@interspire.com>
     * @copyright    (c) 2010 Chris Boulton
     * @license        http://www.opensource.org/licenses/mit-license.php
     */
    class RedisApi extends Redisent
    {

    }
}

class Resque_Redis extends redisApi
{
    public function __construct($host, $port, $password = null)
    {
        if (is_subclass_of($this, 'Redis')) {
            parent::__construct($host, $port, 5, $password);
        } else {
            parent::__construct($host, $port);
        }
    }
}
