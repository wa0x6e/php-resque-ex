<?php
require_once dirname(__FILE__) . '/Stat.php';
require_once dirname(__FILE__) . '/Event.php';
require_once dirname(__FILE__) . '/Job.php';
require_once dirname(__FILE__) . '/Job/DirtyExitException.php';

// Find and initialize Composer
$files = array(
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
    __DIR__ . '/../../../../autoload.php',
    __DIR__ . '/../vendor/autoload.php'
);

foreach ($files as $file) {
    if (file_exists($file)) {
        require_once $file;
        break;
    }
}

if (!class_exists('Composer\Autoload\ClassLoader', false)) {
    die(
        'You need to set up the project dependencies using the following commands:' . PHP_EOL .
        'curl -s http://getcomposer.org/installer | php' . PHP_EOL .
        'php composer.phar install' . PHP_EOL
    );
}

/**
 * Resque worker that handles checking queues for jobs, fetching them
 * off the queues, running them and handling the result.
 *
 * @package     Resque/Worker
 * @author      Chris Boulton <chris@bigcommerce.com>
 * @license     http://www.opensource.org/licenses/mit-license.php
 */
class Resque_Worker
{
    const LOG_NONE = 0;
    const LOG_NORMAL = 1;
    const LOG_VERBOSE = 2;


    const LOG_TYPE_DEBUG = 100;
    const LOG_TYPE_INFO = 200;
    const LOG_TYPE_WARNING = 300;
    const LOG_TYPE_ERROR = 400;
    const LOG_TYPE_CRITICAL = 500;
    const LOG_TYPE_ALERT = 550;

    public $logOutput = STDOUT;

    /**
     * @var int Current log level of this worker.
     */
    public $logLevel = self::LOG_NONE;

    /**
     * @var array Array of all associated queues for this worker.
     */
    protected $queues = array();

    /**
     * @var string The hostname of this worker.
     */
    protected $hostname;

    /**
     * @var boolean True if on the next iteration, the worker should shutdown.
     */
    protected $shutdown = false;

    /**
     * @var boolean True if this worker is paused.
     */
    protected $paused = false;

    /**
     * @var string String identifying this worker.
     */
    protected $id;

    /**
     * @var Resque_Job Current job, if any, being processed by this worker.
     *
     */
    protected $currentJob = null;

    /**
     * @var int Process ID of child worker processes.
     */
    protected $child = null;

    protected $logger = null;

    /**
     * Return all workers known to Resque as instantiated instances.
     * @return array
     */
    public static function all()
    {
        $workers = Resque::redis()->smembers('workers');
        if (!is_array($workers)) {
            $workers = array();
        }

        $instances = array();
        foreach ($workers as $workerId) {
            $instances[] = self::find($workerId);
        }
        return $instances;
    }

    /**
     * Given a worker ID, check if it is registered/valid.
     *
     * @param string $workerId ID of the worker.
     * @return boolean True if the worker exists, false if not.
     */
    public static function exists($workerId)
    {
        return (bool)Resque::redis()->sismember('workers', $workerId);
    }

    /**
     * Given a worker ID, find it and return an instantiated worker class for it.
     *
     * @param string $workerId The ID of the worker.
     * @return Resque_Worker Instance of the worker. False if the worker does not exist.
     */
    public static function find($workerId)
    {
        if (!self::exists($workerId) || false === strpos($workerId, ":")) {
            return false;
        }

        list($hostname, $pid, $queues) = explode(':', $workerId, 3);
        $queues = explode(',', $queues);
        $worker = new self($queues);
        $worker->setId($workerId);
        $worker->logger = $worker->getLogger($workerId);
        return $worker;
    }

    /**
     * Set the ID of this worker to a given ID string.
     *
     * @param string $workerId ID for the worker.
     */
    public function setId($workerId)
    {
        $this->id = $workerId;
    }

    /**
     * Instantiate a new worker, given a list of queues that it should be working
     * on. The list of queues should be supplied in the priority that they should
     * be checked for jobs (first come, first served)
     *
     * Passing a single '*' allows the worker to work on all queues in alphabetical
     * order. You can easily add new queues dynamically and have them worked on using
     * this method.
     *
     * @param string|array $queues String with a single queue name, array with multiple.
     */
    public function __construct($queues)
    {
        if (!is_array($queues)) {
            $queues = array($queues);
        }

        $this->queues = $queues;
        if (function_exists('gethostname')) {
            $hostname = gethostname();
        } else {
            $hostname = php_uname('n');
        }
        $this->hostname = $hostname;
        $this->id = $this->hostname . ':'.getmypid() . ':' . implode(',', $this->queues);
    }

    /**
     * The primary loop for a worker which when called on an instance starts
     * the worker's life cycle.
     *
     * Queues are checked every $interval (seconds) for new jobs.
     *
     * @param int $interval How often to check for new jobs across the queues.
     */
    public function work($interval = 5)
    {
        $this->updateProcLine('Starting');
        $this->startup();

        while (true) {
            if ($this->shutdown) {
                break;
            }

            // Attempt to find and reserve a job
            $job = false;
            if (!$this->paused) {
                try {
                    $job = $this->reserve();
                }
                catch (\RedisException $e) {
                    $this->log(array('message' => 'Redis exception caught: ' . $e->getMessage(), 'data' => array('type' => 'fail', 'log' => $e->getMessage(), 'time' => time())), self::LOG_TYPE_ALERT);
                }
            }

            if (!$job) {
                // For an interval of 0, break now - helps with unit testing etc
                if ($interval == 0) {
                    break;
                }
                // If no job was found, we sleep for $interval before continuing and checking again
                $this->log(array('message' => 'Sleeping for ' . $interval, 'data' => array('type' => 'sleep', 'second' => $interval)), self::LOG_TYPE_DEBUG);
                if ($this->paused) {
                    $this->updateProcLine('Paused');
                } else {
                    $this->updateProcLine('Waiting for ' . implode(',', $this->queues));
                }
                usleep($interval * 1000000);
                continue;
            }

            $this->log(array('message' => 'got ' . $job, 'data' => array('type' => 'got', 'args' => $job)), self::LOG_TYPE_INFO);
            Resque_Event::trigger('beforeFork', $job);
            $this->workingOn($job);

            $workerName = $this->hostname . ':'.getmypid();

            $this->child = $this->fork();

            // Forked and we're the child. Run the job.
            if ($this->child === 0 || $this->child === false) {
                $status = 'Processing ID:' . $job->payload['id'] .  ' in ' . $job->queue;
                $this->updateProcLine($status);
                $this->log(array('message' => $status, 'data' => array('type' => 'process', 'worker' => $workerName, 'job_id' => $job->payload['id'])), self::LOG_TYPE_INFO);
                $this->perform($job);
                if ($this->child === 0) {
                    exit(0);
                }
            }

            if ($this->child > 0) {
                // Parent process, sit and wait
                $status = 'Forked ' . $this->child . ' for ID:' . $job->payload['id'];
                $this->updateProcLine($status);
                $this->log(array('message' => $status, 'data' => array('type' => 'fork', 'worker' => $workerName, 'job_id' => $job->payload['id'])), self::LOG_TYPE_DEBUG);

                // Wait until the child process finishes before continuing
                pcntl_wait($status);
                $exitStatus = pcntl_wexitstatus($status);
                if ($exitStatus !== 0) {
                    $job->fail(new Resque_Job_DirtyExitException('Job exited with exit code ' . $exitStatus));
                }
            }

            $this->child = null;
            $this->doneWorking();
        }

        $this->unregisterWorker();
    }

    /**
     * Process a single job.
     *
     * @param Resque_Job $job The job to be processed.
     */
    public function perform(Resque_Job $job)
    {
        $startTime = microtime(true);
        try {
            Resque_Event::trigger('afterFork', $job);
            $job->perform();
            $this->log(array('message' => 'done ID:' . $job->payload['id'], 'data' => array('type' => 'done', 'job_id' => $job->payload['id'], 'time' => round(microtime(true) - $startTime, 3) * 1000)), self::LOG_TYPE_INFO);
        } catch (Exception $e) {
            $this->log(array('message' => $job . ' failed: ' . $e->getMessage(), 'data' => array('type' => 'fail', 'log' => $e->getMessage(), 'job_id' => $job->payload['id'], 'time' => round(microtime(true) - $startTime, 3) * 1000)), self::LOG_TYPE_ERROR);
            $job->fail($e);
            return;
        }

        $job->updateStatus(Resque_Job_Status::STATUS_COMPLETE);
    }

    /**
     * Attempt to find a job from the top of one of the queues for this worker.
     *
     * @return object|boolean Instance of Resque_Job if a job is found, false if not.
     */
    public function reserve()
    {
        $queues = $this->queues();
        if (!is_array($queues)) {
            return;
        }
        foreach ($queues as $queue) {
            $this->log(array('message' => 'Checking ' . $queue, 'data' => array('type' => 'check', 'queue' => $queue)), self::LOG_TYPE_DEBUG);
            $job = Resque_Job::reserve($queue);
            if ($job) {
                $this->log(array('message' => 'Found job on ' . $queue, 'data' => array('type' => 'found', 'queue' => $queue)), self::LOG_TYPE_DEBUG);
                return $job;
            }
        }

        return false;
    }

    /**
     * Return an array containing all of the queues that this worker should use
     * when searching for jobs.
     *
     * If * is found in the list of queues, every queue will be searched in
     * alphabetic order. (@see $fetch)
     *
     * @param boolean $fetch If true, and the queue is set to *, will fetch
     * all queue names from redis.
     * @return array Array of associated queues.
     */
    public function queues($fetch = true)
    {
        if (!in_array('*', $this->queues) || $fetch == false) {
            return $this->queues;
        }

        $queues = Resque::queues();
        sort($queues);
        return $queues;
    }

    /**
     * Attempt to fork a child process from the parent to run a job in.
     *
     * Return values are those of pcntl_fork().
     *
     * @return int -1 if the fork failed, 0 for the forked child, the PID of the child for the parent.
     */
    protected function fork()
    {
        if (!function_exists('pcntl_fork')) {
            return false;
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork child worker.');
        }

        return $pid;
    }

    /**
     * Perform necessary actions to start a worker.
     */
    protected function startup()
    {
        $this->log(array('message' => 'Starting worker ' . $this, 'data' => array('type' => 'start', 'worker' => (string) $this)), self::LOG_TYPE_INFO);

        $this->registerSigHandlers();
        $this->pruneDeadWorkers();
        Resque_Event::trigger('beforeFirstFork', $this);
        $this->registerWorker();
    }

    /**
     * On supported systems (with the PECL proctitle module installed), update
     * the name of the currently running process to indicate the current state
     * of a worker.
     *
     * @param string $status The updated process title.
     */
    protected function updateProcLine($status)
    {
        $processTitle = 'resque-' . Resque::VERSION . ': ' . $status;

        if (function_exists('cli_set_process_title')) {
            cli_set_process_title($processTitle);
        } elseif (function_exists('setproctitle')) {
            setproctitle($processTitle);
        }
    }

    /**
     * Register signal handlers that a worker should respond to.
     *
     * TERM: Shutdown immediately and stop processing jobs.
     * INT: Shutdown immediately and stop processing jobs.
     * QUIT: Shutdown after the current job finishes processing.
     * USR1: Kill the forked child immediately and continue processing jobs.
     */
    protected function registerSigHandlers()
    {
        if (!function_exists('pcntl_signal')) {
            $this->log(array('message' => 'Signals handling is unsupported', 'data' => array('type' => 'signal')), self::LOG_TYPE_WARNING);
            return;
        }

        declare(ticks = 1);
        pcntl_signal(SIGTERM, array($this, 'shutDownNow'));
        pcntl_signal(SIGINT, array($this, 'shutDownNow'));
        pcntl_signal(SIGQUIT, array($this, 'shutdown'));
        pcntl_signal(SIGUSR1, array($this, 'killChild'));
        pcntl_signal(SIGUSR2, array($this, 'pauseProcessing'));
        pcntl_signal(SIGCONT, array($this, 'unPauseProcessing'));
        pcntl_signal(SIGPIPE, array($this, 'reestablishRedisConnection'));
        $this->log(array('message' => 'Registered signals', 'data' => array('type' => 'signal')), self::LOG_TYPE_DEBUG);
    }

    /**
     * Signal handler callback for USR2, pauses processing of new jobs.
     */
    public function pauseProcessing()
    {
        $this->log(array('message' => 'USR2 received; pausing job processing', 'data' => array('type' => 'pause')), self::LOG_TYPE_INFO);
        $this->paused = true;
    }

    /**
     * Signal handler callback for CONT, resumes worker allowing it to pick
     * up new jobs.
     */
    public function unPauseProcessing()
    {
        $this->log(array('message' => 'CONT received; resuming job processing', 'data' => array('type' => 'resume')), self::LOG_TYPE_INFO);
        $this->paused = false;
    }

    /**
     * Signal handler for SIGPIPE, in the event the redis connection has gone away.
     * Attempts to reconnect to redis, or raises an Exception.
     */
    public function reestablishRedisConnection()
    {
        $this->log(array('message' => 'SIGPIPE received; attempting to reconnect', 'data' => array('type' => 'reconnect')), self::LOG_TYPE_INFO);
        Resque::redis()->establishConnection();
    }

    /**
     * Schedule a worker for shutdown. Will finish processing the current job
     * and when the timeout interval is reached, the worker will shut down.
     */
    public function shutdown()
    {
        $this->shutdown = true;
        $this->log(array('message' => 'Exiting...', 'data' => array('type' => 'shutdown')), self::LOG_TYPE_INFO);
    }

    /**
     * Force an immediate shutdown of the worker, killing any child jobs
     * currently running.
     */
    public function shutdownNow()
    {
        $this->shutdown();
        $this->killChild();
    }

    /**
     * Kill a forked child job immediately. The job it is processing will not
     * be completed.
     */
    public function killChild()
    {
        if (!$this->child) {
            $this->log(array('message' => 'No child to kill.', 'data' => array('type' => 'kill', 'child' => null)), self::LOG_TYPE_DEBUG);
            return;
        }

        $this->log(array('message' => 'Killing child at ' . $this->child, 'data' => array('type' => 'kill', 'child' => $this->child)), self::LOG_TYPE_DEBUG);
        if (exec('ps -o pid,state -p ' . $this->child, $output, $returnCode) && $returnCode != 1) {
            $this->log(array('message' => 'Killing child at ' . $this->child, 'data' => array('type' => 'kill', 'child' => $this->child)), self::LOG_TYPE_DEBUG);
            posix_kill($this->child, SIGKILL);
            $this->child = null;
        } else {
            $this->log(array('message' => 'Child ' . $this->child . ' not found, restarting.', 'data' => array('type' => 'kill', 'child' => $this->child)), self::LOG_TYPE_ERROR);
            $this->shutdown();
        }
    }

    /**
     * Look for any workers which should be running on this server and if
     * they're not, remove them from Redis.
     *
     * This is a form of garbage collection to handle cases where the
     * server may have been killed and the Resque workers did not die gracefully
     * and therefore leave state information in Redis.
     */
    public function pruneDeadWorkers()
    {
        $workerPids = $this->workerPids();
        $workers = self::all();
        foreach ($workers as $worker) {
            if (is_object($worker)) {
                list($host, $pid, $queues) = explode(':', (string)$worker, 3);
                if ($host != $this->hostname || in_array($pid, $workerPids) || $pid == getmypid()) {
                    continue;
                }
                $this->log(array('message' => 'Pruning dead worker: ' . (string)$worker, 'data' => array('type' => 'prune')), self::LOG_TYPE_DEBUG);
                $worker->unregisterWorker();
            }
        }
    }

    /**
     * Return an array of process IDs for all of the Resque workers currently
     * running on this machine.
     *
     * @return array Array of Resque worker process IDs.
     */
    public function workerPids()
    {
        $pids = array();
        exec('ps -A -o pid,comm | grep [r]esque', $cmdOutput);
        foreach ($cmdOutput as $line) {
            list($pids[]) = explode(' ', trim($line), 2);
        }
        return $pids;
    }

    /**
     * Register this worker in Redis.
     */
    public function registerWorker()
    {
        Resque::redis()->sadd('workers', (string)$this);
        Resque::redis()->set('worker:' . (string)$this . ':started', strftime('%a %b %d %H:%M:%S %Z %Y'));
    }

    /**
     * Unregister this worker in Redis. (shutdown etc)
     */
    public function unregisterWorker()
    {
        if (is_object($this->currentJob)) {
            $this->currentJob->fail(new Resque_Job_DirtyExitException);
        }

        $id = (string)$this;
        Resque::redis()->srem('workers', $id);
        Resque::redis()->del('worker:' . $id);
        Resque::redis()->del('worker:' . $id . ':started');
        Resque_Stat::clear('processed:' . $id);
        Resque_Stat::clear('failed:' . $id);
        Resque::redis()->hdel('workerLogger', $id);
    }

    /**
     * Tell Redis which job we're currently working on.
     *
     * @param object $job Resque_Job instance containing the job we're working on.
     */
    public function workingOn(Resque_Job $job)
    {
        $job->worker = $this;
        $this->currentJob = $job;
        $job->updateStatus(Resque_Job_Status::STATUS_RUNNING);
        $data = json_encode(
            array(
                'queue' => $job->queue,
                'run_at' => strftime('%a %b %d %H:%M:%S %Z %Y'),
                'payload' => $job->payload
            )
        );
        Resque::redis()->set('worker:' . $job->worker, $data);
    }

    /**
     * Notify Redis that we've finished working on a job, clearing the working
     * state and incrementing the job stats.
     */
    public function doneWorking()
    {
        $this->currentJob = null;
        Resque_Stat::incr('processed');
        Resque_Stat::incr('processed:' . (string)$this);
        Resque::redis()->del('worker:' . (string)$this);
    }

    /**
     * Generate a string representation of this worker.
     *
     * @return string String identifier for this worker instance.
     */
    public function __toString()
    {
        return $this->id;
    }

    /**
     * Output a given log message to STDOUT.
     *
     * @param string $message Message to output.
     * @return  boolean True if the message is logged
     */
    public function log($message, $code = self::LOG_TYPE_INFO)
    {
        if ($this->logLevel === self::LOG_NONE) {
            return false;
        }

        /*if ($this->logger === null) {
            if ($this->logLevel === self::LOG_NORMAL && $code !== self::LOG_TYPE_DEBUG) {
                fwrite($this->logOutput, "*** " . $message['message'] . "\n");
            } else if ($this->logLevel === self::LOG_VERBOSE) {
                fwrite($this->logOutput, "** [" . strftime('%T %Y-%m-%d') . "] " . $message['message'] . "\n");
            } else {
                return false;
            }
            return true;
        } else {*/
        $extra = array();

        if (is_array($message)) {
            $extra = $message['data'];
            $message = $message['message'];
        }

        if (!isset($extra['worker'])) {
            if ($this->child > 0) {
                $extra['worker'] = $this->hostname . ':' . getmypid();
            } else {
                list($host, $pid, $queues) = explode(':', (string) $this, 3);
                $extra['worker'] = $host . ':' . $pid;
            }
        }

        if (($this->logLevel === self::LOG_NORMAL || $this->logLevel === self::LOG_VERBOSE) && $code !== self::LOG_TYPE_DEBUG) {

            if ($this->logger === null) {
                fwrite($this->logOutput, "[" . date('c') . "] " . $message . "\n");
            } else {
                switch ($code) {
                    case self::LOG_TYPE_INFO:
                        $this->logger->addInfo($message, $extra);
                        break;
                    case self::LOG_TYPE_WARNING:
                        $this->logger->addWarning($message, $extra);
                        break;
                    case self::LOG_TYPE_ERROR:
                        $this->logger->addError($message, $extra);
                        break;
                    case self::LOG_TYPE_CRITICAL:
                        $this->logger->addCritical($message, $extra);
                        break;
                    case self::LOG_TYPE_ALERT:
                        $this->logger->addAlert($message, $extra);
                }
            }



        } else if ($code === self::LOG_TYPE_DEBUG && $this->logLevel === self::LOG_VERBOSE) {
            if ($this->logger === null) {
                fwrite($this->logOutput, "[" . date('c') . "] " . $message . "\n");
            } else {
                $this->logger->addDebug($message, $extra);
            }
        } else {
            return false;
        }

         return true;
        //}
    }

    public function registerLogger($logger = null)
    {
        $this->logger = $logger->getInstance();
        Resque::redis()->hset('workerLogger', (string)$this, json_encode(array($logger->handler, $logger->target)));
    }

    public function getLogger($workerId)
    {
        $settings = json_decode(Resque::redis()->hget('workerLogger', (string)$workerId));
        $logger = new MonologInit\MonologInit($settings[0], $settings[1]);
        return $logger->getInstance();
    }

    /**
     * Return an object describing the job this worker is currently working on.
     *
     * @return object Object with details of current job.
     */
    public function job()
    {
        $job = Resque::redis()->get('worker:' . $this);
        if (!$job) {
            return array();
        } else {
            return json_decode($job, true);
        }
    }

    /**
     * Get a statistic belonging to this worker.
     *
     * @param string $stat Statistic to fetch.
     * @return int Statistic value.
     */
    public function getStat($stat)
    {
        return Resque_Stat::get($stat . ':' . $this);
    }
}
