<?php

/**
 * Resque job performer.
 *
 * @package        Resque/Job
 * @author        Protec Innovations <dev@protecinnovations.co.uk>
 * @license        http://www.opensource.org/licenses/mit-license.php
 */
class Resque_Job_Performer
{
    const HOOKS_AFTER = 'after';
    const HOOKS_AROUND = 'around';
    const HOOKS_BEFORE = 'before';

    /**
     * @var object $job
     */
    private $job;

    /**
     * @var array $args
     */
    private $args;

    /**
     * @var array $hooks
     */
    private $hooks;

    /**
     * @var bool $performed
     */
    private $performed = false;

    /**
     * Resque_Job_Performer constructor.
     *
     * @param object $job
     * @param array $args
     * @param array $hooks
     */
    public function __construct($job, array $args, array $hooks)
    {
        $this->job = $job;
        $this->args = $args;
        $this->hooks = $hooks;
    }

    /**
     * perform
     *
     * @return bool
     */
    public function perform()
    {
        $this->callBeforeHooks();

        $this->executeJob();

        $this->callHooks(self::HOOKS_AFTER);

        return $this->performed;
    }

    /**
     * callBeforeHooks
     *
     */
    protected function callBeforeHooks()
    {
        $this->callHooks(self::HOOKS_BEFORE);
    }

    /**
     * callHooks
     *
     * @param string $hook_type
     */
    protected function callHooks($hook_type)
    {
        foreach ($this->hooks[$hook_type] as $hook) {
            $this->performHook($hook);
        }
    }

    /**
     * performHook
     *
     * @param string $hook
     */
    protected function performHook($hook)
    {
        call_user_func_array([$this->job, $hook], $this->args);
    }

    /**
     * executeJob
     *
     */
    protected function executeJob()
    {
        if (empty($this->hooks[self::HOOKS_AROUND])) {
            $this->performJob();
        } else {
            $this->callAroundHooks();
        }
    }

    /**
     * callAroundHooks
     *
     */
    protected function callAroundHooks()
    {
        $hooks = $this->nestedAroundHooks();

        $hooks();
    }

    /**
     * nestedAroundHooks
     *
     * @return Closure
     */
    protected function nestedAroundHooks()
    {
        $final_hook = [$this, 'performJob'];

        $around_hooks = array_reverse($this->hooks[self::HOOKS_AROUND]);

        $args = $this->args;

        $job = $this->job;

        return array_reduce(
            $around_hooks,
            function ($last_hook, $hook) use ($args, $job) {
                $callback = [$job, $hook];
                return function() use ($callback, $last_hook, $args) {
                    call_user_func_array($callback, [$args, $last_hook]);
                };
            },
            $final_hook
        );
    }

    /**
     * performJob
     *
     * @return mixed
     */
    public function performJob()
    {
        $result = $this->job->perform($this->args);
        $this->performed = true;
        return $result;
    }
}
