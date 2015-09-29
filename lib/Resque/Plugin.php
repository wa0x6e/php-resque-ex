<?php

/**
 * Plugin
 *
 * @package Resque
 * @author Protec Innovations <support@protecinnovations.co.uk>
 * @license http://www.opensource.org/licenses/mit-license.php
 */
class Resque_Plugin
{
    const PREFIX_BEFORE_PERFORM = 'beforePerform';
    const PREFIX_AFTER_PERFORM = 'afterPerform';
    const PREFIX_AROUND_PERFORM = 'aroundPerform';

    /**
     * beforeHooks
     *
     * @param object $job
     *
     * @return array
     */
    public static function beforeHooks($job)
    {
        return self::getMethodsWithPrefix($job, self::PREFIX_BEFORE_PERFORM);
    }

    /**
     * afterHooks
     *
     * @param object $job
     *
     * @return array
     */
    public static function afterHooks($job)
    {
        return self::getMethodsWithPrefix($job, self::PREFIX_AFTER_PERFORM);
    }

    /**
     * aroundHooks
     *
     * @param object $job
     *
     * @return array
     */
    public static function aroundHooks($job)
    {
        return self::getMethodsWithPrefix($job, self::PREFIX_AROUND_PERFORM);
    }

    /**
     * getMethodsWithPrefix
     *
     * @param object $class
     * @param string $prefix
     *
     * @return array
     */
    protected static function getMethodsWithPrefix($class, $prefix)
    {
        $reflection = new ReflectionClass($class);

        $has_basic_hook = false;

        $return = [];

        foreach ($reflection->getMethods() as $method) {
            $method_name = $method->getName();

            if (substr($method_name, 0, strlen($prefix)) == $prefix) {
                if (strlen($prefix) == strlen($method_name)) {
                    $has_basic_hook = true;
                } else {
                    $return[] = $method_name;
                }
            }
        }

        // Make sure we add the "basic" one last
        if ($has_basic_hook) {
            $return[] = $prefix;
        }

        return $return;
    }
}
