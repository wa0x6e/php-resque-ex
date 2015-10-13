<?php
// Third- party apps may have already loaded Resident from elsewhere
// so lets be careful.
if (!class_exists('RedisentCluster', false)) {
    require_once dirname(__FILE__) . '/../Redisent/RedisentCluster.php';
}

/**
 * Extended Redisent class used by Resque for all communication with
 * redis.
 *
 * @package        Resque/Redis
 * @author        Chris Boulton <chris@bigcommerce.com>
 * @license        http://www.opensource.org/licenses/mit-license.php
 */
class Resque_RedisCluster extends RedisentCluster
{
}

?>
