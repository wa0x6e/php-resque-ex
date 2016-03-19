<?php
use Predis\Client as Predis;
use Predis\Command\Processor\KeyPrefixProcessor;

class RedisApi extends Predis
{
    private static $namespace = 'resque:';
    private $prefix = null;

    /**
     * RedisApi constructor.
     * set all options and add prefix processor to make prefix customizable
     *
     * @param array|string $host
     * @param integer $port
     * @param integer $timeout
     * @param null|string $password
     */
    public function __construct($host, $port = 6379, $timeout = 5, $password = null)
    {
        $options = [
            'port' => $port,
            'database' => 0,
            'timeout' => $timeout,
            'password' => $password,
        ];

        if(is_array($host)){
            $options['cluster'] = 'redis';
        }

        parent::__construct($host,$options);
        $this->prefix = new KeyPrefixProcessor(self::$namespace);
        $this->getProfile()->setProcessor($this->prefix);
    }

    /**
     * prepare and set prefix (namespace)
     *
     * @param null|string $prefix
     * @return string namespace
     */
    public function prefix($prefix = null)
    {
        if (empty($prefix)) $prefix = self::$namespace;
        if (strpos($prefix, ':') === false) {
            $prefix .= ':';
        }
        self::$namespace = $prefix;
        $this->prefix->setPrefix(self::$namespace);

        return self::$namespace;
    }

    /**
     * get prefix (namespace)
     *
     * @return string namespace
     */
    public static function getPrefix()
    {
        return self::$namespace;
    }

}

class Resque_Redis extends RedisApi
{
    public function __construct($host, $port = 6379, $password = null)
    {
        parent::__construct($host, $port, 5, $password);
    }
}