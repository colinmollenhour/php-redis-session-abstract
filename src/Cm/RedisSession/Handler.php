<?php
/*
==New BSD License==

Copyright (c) 2013, Colin Mollenhour
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

    * Redistributions of source code must retain the above copyright
      notice, this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright
      notice, this list of conditions and the following disclaimer in the
      documentation and/or other materials provided with the distribution.
    * The name of Colin Mollenhour may not be used to endorse or promote products
      derived from this software without specific prior written permission.
    * Redistributions in any form must not change the Cm_RedisSession namespace.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER BE LIABLE FOR ANY
DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */
namespace Cm\RedisSession;

/**
 * Redis session handler with optimistic locking.
 *
 * Features:
 *  - When a session's data exceeds the compression threshold the session data will be compressed.
 *  - Compression libraries supported are 'gzip', 'lzf', 'lz4' and 'snappy'.
 *  - Compression can be enabled, disabled, or reconfigured on the fly with no loss of session data.
 *  - Expiration is handled by Redis. No garbage collection needed.
 *  - Logs when sessions are not written due to not having or losing their lock.
 *  - Limits the number of concurrent lock requests.
 *
 * Locking Algorithm Properties:
 *  - Only one process may get a write lock on a session.
 *  - A process may lose it's lock if another process breaks it, in which case the session will not be written.
 *  - The lock may be broken after BREAK_AFTER seconds and the process that gets the lock is indeterminate.
 *  - Only MAX_CONCURRENCY processes may be waiting for a lock for the same session or else a ConcurrentConnectionsExceededException will be thrown.
 *  - Detects crashed processes to prevent session deadlocks (Linux only).
 *  - Detects inactive waiting processes to prevent false-positives in concurrency throttling.
 */

use Cm\RedisSession\Handler\ConfigInterface;
use Cm\RedisSession\Handler\ConfigSentinelPasswordInterface;
use Cm\RedisSession\Handler\LoggerInterface;

class Handler implements \SessionHandlerInterface
{
    /**
     * Sleep 0.5 seconds between lock attempts (1,000,000 == 1 second)
     */
    const SLEEP_TIME         = 500000;

    /**
     * Try to detect zombies every this many tries
     */
    const DETECT_ZOMBIES     = 20;

    /**
     * Session prefix
     */
    const SESSION_PREFIX     = 'sess_';

    /**
     * Bots get shorter session lifetimes
     */
    const BOT_REGEX          = '/^alexa|^blitz\.io|bot|^browsermob|crawl|^curl|^facebookexternalhit|feed|google web preview|^ia_archiver|indexer|^java|jakarta|^libwww-perl|^load impact|^magespeedtest|monitor|^Mozilla$|nagios |^\.net|^pinterest|postrank|slurp|spider|uptime|^wget|yandex|^elb-healthchecker|binglocalsearch/i';

    /**
     * Default connection timeout
     */
    const DEFAULT_TIMEOUT               = 2.5;

    /**
     * Default compression threshold
     */
    const DEFAULT_COMPRESSION_THRESHOLD = 2048;

    /**
     * Default compression library
     */
    const DEFAULT_COMPRESSION_LIBRARY   = 'gzip';

    /**
     * Default log level
     */
    const DEFAULT_LOG_LEVEL             = LoggerInterface::ALERT;

    /**
     * Maximum number of processes that can wait for a lock on one session
     */
    const DEFAULT_MAX_CONCURRENCY       = 6;

    /**
     * Try to break the lock after this many seconds
     */
    const DEFAULT_BREAK_AFTER           = 30;

    /**
     * Try to break lock for at most this many seconds
     */
    const DEFAULT_FAIL_AFTER            = 15;

    /**
     * The session lifetime for non-bots on the first write
     */
    const DEFAULT_FIRST_LIFETIME        = 600;

    /**
     * The session lifetime for bots on the first write
     */
    const DEFAULT_BOT_FIRST_LIFETIME    = 60;

    /**
     * The session lifetime for bots - shorter to prevent bots from wasting backend storage
     */
    const DEFAULT_BOT_LIFETIME          = 7200;

    /**
     * Redis backend limit
     */
    const DEFAULT_MAX_LIFETIME          = 2592000;

    /**
     * Default min lifetime
     */
    const DEFAULT_MIN_LIFETIME          = 60;

    /**
     * Default host
     */
    const DEFAULT_HOST                  = '127.0.0.1';

    /**
     * Default port
     */
    const DEFAULT_PORT                  = 6379;

    /**
     * Default database
     */
    const DEFAULT_DATABASE              = 0;

    /**
     * Default lifetime
     */
    const DEFAULT_LIFETIME              = 60;

    /**
     * @var \Credis_Client
     */
    protected $_redis;

    /**
     * @var int
     */
    protected $_dbNum;

    /**
     * @var string
     */
    protected $_compressionThreshold;

    /**
     * @var string
     */
    protected $_compressionLibrary;

    /**
     * @var int
     */
    protected $_maxConcurrency;

    /**
     * @var int
     */
    protected $_breakAfter;

    /**
     * @var int
     */
    protected $_failAfter;

    /**
     * @var boolean
     */
    protected $_useLocking;

    /**
     * @var boolean
     */
    protected $_hasLock;

    /**
     * Avoid infinite loops
     *
     * @var boolean
     */
    protected $_sessionWritten;

    /**
     * Set expire time based on activity
     *
     * @var int
     */
    protected $_sessionWrites;

    /**
     * @var int
     */
    protected $_maxLifetime;

    /**
     * @var int
     */
    protected $_minLifetime;

    /**
     * For debug or informational purposes
     *
     * @var int
     */
    protected $failedLockAttempts = 0;

    /**
     * @var ConfigInterface
     */
    protected $config;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var int
     */
    protected $_lifeTime;

    /** @var null|array Callback method to call. It will receive 2 parameters: $userAgent, $isBot */
    static public $_botCheckCallback = null;

    /**
     * @var boolean
     */
    private $_readOnly;

    /**
     * @param ConfigInterface $config
     * @param LoggerInterface $logger
     * @param boolean $readOnly
     * @throws ConnectionFailedException
     */
    public function __construct(ConfigInterface $config, LoggerInterface $logger, $readOnly = false)
    {
        $this->config = $config;
        $this->logger = $logger;

        $this->logger->setLogLevel($this->config->getLogLevel() ?: self::DEFAULT_LOG_LEVEL);
        $timeStart = microtime(true);

        // Database config
        $host =             $this->config->getHost() ?: self::DEFAULT_HOST;
        $port =             $this->config->getPort() ?: self::DEFAULT_PORT;
        $pass =             $this->config->getPassword() ?: null;
        $timeout =          $this->config->getTimeout() ?: self::DEFAULT_TIMEOUT;
        $persistent =       $this->config->getPersistentIdentifier() ?: '';
        $this->_dbNum =     $this->config->getDatabase() ?: self::DEFAULT_DATABASE;

        // General config
        $this->_readOnly =              $readOnly;
        $this->_compressionThreshold =  $this->config->getCompressionThreshold() ?: self::DEFAULT_COMPRESSION_THRESHOLD;
        $this->_compressionLibrary =    $this->config->getCompressionLibrary() ?: self::DEFAULT_COMPRESSION_LIBRARY;
        $this->_maxConcurrency =        $this->config->getMaxConcurrency() ?: self::DEFAULT_MAX_CONCURRENCY;
        $this->_failAfter =             $this->config->getFailAfter() ?: self::DEFAULT_FAIL_AFTER;
        $this->_maxLifetime =           $this->config->getMaxLifetime() ?: self::DEFAULT_MAX_LIFETIME;
        $this->_minLifetime =           $this->config->getMinLifetime() ?: self::DEFAULT_MIN_LIFETIME;
        $this->_useLocking =            ! $this->config->getDisableLocking();

        // Use sleep time multiplier so fail after time is in seconds
        $this->_failAfter = (int) round((1000000 / self::SLEEP_TIME) * $this->_failAfter);

        // Sentinel config
        $sentinelServers =         $this->config->getSentinelServers();
        $sentinelMaster =          $this->config->getSentinelMaster();
        $sentinelVerifyMaster =    $this->config->getSentinelVerifyMaster();
        $sentinelConnectRetries =  $this->config->getSentinelConnectRetries();
        $sentinelPassword =        $this->config instanceof ConfigSentinelPasswordInterface
            ? $this->config->getSentinelPassword()
            : $pass;

        // Connect and authenticate
        if ($sentinelServers && $sentinelMaster) {
            $servers = preg_split('/\s*,\s*/', trim($sentinelServers), -1, PREG_SPLIT_NO_EMPTY);
            $sentinel = NULL;
            $exception = NULL;
            for ($i = 0; $i <= $sentinelConnectRetries; $i++) // Try to connect to sentinels in round-robin fashion
            foreach ($servers as $server) {
                try {
                    $sentinelClient = new \Credis_Client($server, NULL, $timeout, $persistent);
                    $sentinelClient->forceStandalone();
                    $sentinelClient->setMaxConnectRetries(0);
                    if ($sentinelPassword) {
                        try {
                            $sentinelClient->auth($sentinelPassword);
                        } catch (\CredisException $e) {
                            // Prevent throwing exception if Sentinel has no password set (error messages are different between redis 5 and redis 6)
                            if ($e->getCode() !== 0 || (
                                strpos($e->getMessage(), 'ERR Client sent AUTH, but no password is set') === false && 
                                strpos($e->getMessage(), 'ERR AUTH <password> called without any password configured for the default user. Are you sure your configuration is correct?') === false)
                            ) {
                                throw $e;
                            }
                        }
                    }
                   
                    $sentinel = new \Credis_Sentinel($sentinelClient);
                    $sentinel
                        ->setClientTimeout($timeout)
                        ->setClientPersistent($persistent);
                    $redisMaster = $sentinel->getMasterClient($sentinelMaster);
                    if ($pass) $redisMaster->auth($pass);

                    // Verify connected server is actually master as per Sentinel client spec
                    if ($sentinelVerifyMaster) {
                        $roleData = $redisMaster->role();
                        if ( ! $roleData || $roleData[0] != 'master') {
                            usleep(100000); // Sleep 100ms and try again
                            $redisMaster = $sentinel->getMasterClient($sentinelMaster);
                            if ($pass) $redisMaster->auth($pass);
                            $roleData = $redisMaster->role();
                            if ( ! $roleData || $roleData[0] != 'master') {
                                throw new \Exception('Unable to determine master redis server.');
                            }
                        }
                    }
                    if ($this->_dbNum || $persistent) $redisMaster->select(0);

                    $this->_redis = $redisMaster;
                    break 2;
                } catch (\Exception $e) {
                    unset($sentinelClient);
                    $exception = $e;
                }
            }
            unset($sentinel);

            if ( ! $this->_redis) {
                throw new ConnectionFailedException('Unable to connect to a Redis: '.$exception->getMessage(), 0, $exception);
            }
        }
        else {
            $this->_redis = new \Credis_Client($host, $port, $timeout, $persistent, 0, $pass);
            if ($this->hasConnection() == false) {
                throw new ConnectionFailedException('Unable to connect to Redis');
            }
        }

        // Destructor order cannot be predicted
        $this->_redis->setCloseOnDestruct(false);
        $this->_log(
            sprintf(
                "%s initialized for connection to %s:%s after %.5f seconds",
                get_class($this),
                $this->_redis->getHost(),
                $this->_redis->getPort(),
                (microtime(true) - $timeStart)
            )
        );
    }

    /**
     * Open session
     *
     * @param string $savePath ignored
     * @param string $sessionName ignored
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    #[\ReturnTypeWillChange]
    public function open($savePath, $sessionName)
    {
        return true;
    }

    /**
     * @param $msg
     * @param $level
     */
    protected function _log($msg, $level = LoggerInterface::DEBUG)
    {
        $this->logger->log("{$this->_getPid()}: $msg", $level);
    }

    /**
     * Check Redis connection
     *
     * @return bool
     */
    protected function hasConnection()
    {
        try {
            $this->_redis->connect();
            $this->_log("Connected to Redis");
            return true;
        } catch (\Exception $e) {
            $this->logger->logException($e);
            $this->_log('Unable to connect to Redis');
            return false;
        }
    }

    /**
     * Set/unset read only flag
     *
     * @param boolean $readOnly
     * @return self
     */
    public function setReadOnly($readOnly)
    {
        $this->_readOnly = $readOnly;

        return $this;
    }

    /**
     * Fetch session data
     *
     * @param string $sessionId
     * @return string
     * @throws ConcurrentConnectionsExceededException
     */
    #[\ReturnTypeWillChange]
    public function read($sessionId)
    {
        // Get lock on session. Increment the "lock" field and if the new value is 1, we have the lock.
        $sessionId = self::SESSION_PREFIX.$sessionId;
        $tries = $waiting = $lock = 0;
        $lockPid = $oldLockPid = null; // Restart waiting for lock when current lock holder changes
        $detectZombies = false;
        $breakAfter = $this->_getBreakAfter();
        $timeStart = microtime(true);
        $this->_log(sprintf("Attempting to take lock on ID %s", $sessionId));

        $this->_redis->select($this->_dbNum);
        while ($this->_useLocking && !$this->_readOnly)
        {
            // Increment lock value for this session and retrieve the new value
            $oldLock = $lock;
            $lock = $this->_redis->hIncrBy($sessionId, 'lock', 1);

            // Get the pid of the process that has the lock
            if ($lock != 1 && $tries + 1 >= $breakAfter) {
                $lockPid = $this->_redis->hGet($sessionId, 'pid');
            }

            // If we got the lock, update with our pid and reset lock and expiration
            if (   $lock == 1                          // We actually do have the lock
                || (
                    $tries >= $breakAfter   // We are done waiting and want to start trying to break it
                    && $oldLockPid == $lockPid        // Nobody else got the lock while we were waiting
                )
            ) {
                $this->_hasLock = true;
                break;
            }

            // Otherwise, add to "wait" counter and continue
            else if ( ! $waiting) {
                $i = 0;
                do {
                    $waiting = $this->_redis->hIncrBy($sessionId, 'wait', 1);
                } while (++$i < $this->_maxConcurrency && $waiting < 1);
            }

            // Handle overloaded sessions
            else {
                // Detect broken sessions (e.g. caused by fatal errors)
                if ($detectZombies) {
                    $detectZombies = false;
                    // Lock shouldn't be less than old lock (another process broke the lock)
                    if ($lock > $oldLock
                        // Lock should be old+waiting, otherwise there must be a dead process
                        && $lock + 1 < $oldLock + $waiting
                    ) {
                        // Reset session to fresh state
                        $this->_log(
                            sprintf(
                                "Detected zombie waiter after %.5f seconds for ID %s (%d waiting)",
                                (microtime(true) - $timeStart),
                                $sessionId, $waiting
                            ),
                            LoggerInterface::INFO
                        );
                        $waiting = $this->_redis->hIncrBy($sessionId, 'wait', -1);
                        continue;
                    }
                }

                // Limit concurrent lock waiters to prevent server resource hogging
                if ($waiting >= $this->_maxConcurrency) {
                    // Overloaded sessions get 503 errors
                    try {
                        $this->_redis->hIncrBy($sessionId, 'wait', -1);
                        $this->_sessionWritten = true; // Prevent session from getting written
                        $sessionInfo = $this->_redis->hMGet($sessionId, ['writes','req']);
                    } catch (Exception $e) {
                        $this->_log("$e", LoggerInterface::WARNING);
                    }
                    $this->_log(
                        sprintf(
                            'Session concurrency exceeded for ID %s; displaying HTTP 503 (%s waiting, %s total '
                            . 'requests) - Locked URL: %s',
                            $sessionId,
                            $waiting,
                            isset($sessionInfo['writes']) ? $sessionInfo['writes'] : '-',
                            isset($sessionInfo['req']) ? $sessionInfo['req'] : '-'
                        ),
                        LoggerInterface::WARNING
                    );
                    throw new ConcurrentConnectionsExceededException();
                }
            }

            $tries++;
            $oldLockPid = $lockPid;
            $sleepTime = self::SLEEP_TIME;

            // Detect dead lock waiters
            if ($tries % self::DETECT_ZOMBIES == 1) {
                $detectZombies = true;
                $sleepTime += 10000; // sleep + 0.01 seconds
            }
            // Detect dead lock holder every 10 seconds (only works on same node as lock holder)
            if ($tries % self::DETECT_ZOMBIES == 0) {
                $this->_log(
                    sprintf(
                        "Checking for zombies after %.5f seconds of waiting...", (microtime(true) - $timeStart)
                    )
                );

                $pid = $this->_redis->hGet($sessionId, 'pid');
                if ($pid && ! $this->_pidExists($pid)) {
                    // Allow a live process to get the lock
                    $this->_redis->hSet($sessionId, 'lock', 0);
                    $this->_log(
                        sprintf(
                            "Detected zombie process (%s) for %s (%s waiting)",
                            $pid, $sessionId, $waiting
                        ),
                        LoggerInterface::INFO
                    );
                    continue;
                }
            }
            // Timeout
            if ($tries >= $breakAfter + $this->_failAfter) {
                $this->_hasLock = false;
                $this->_log(
                    sprintf(
                        'Giving up on read lock for ID %s after %.5f seconds (%d attempts)',
                        $sessionId,
                        (microtime(true) - $timeStart),
                        $tries
                    ),
                    LoggerInterface::NOTICE
                );
                break;
            }
            else {
                $this->_log(
                    sprintf(
                        "Waiting %.2f seconds for lock on ID %s (%d tries, lock pid is %s, %.5f seconds elapsed)",
                        $sleepTime / 1000000,
                        $sessionId,
                        $tries,
                        $lockPid,
                        (microtime(true) - $timeStart)
                    )
                );
                usleep($sleepTime);
            }
        }
        $this->failedLockAttempts = $tries;

        // Session can be read even if it was not locked by this pid!
        $timeStart2 = microtime(true);
        list($sessionData, $sessionWrites) = array_values($this->_redis->hMGet($sessionId, array('data','writes')));
        $this->_log(sprintf("Data read for ID %s in %.5f seconds", $sessionId, (microtime(true) - $timeStart2)));
        $this->_sessionWrites = (int) $sessionWrites;

        // This process is no longer waiting for a lock
        if ($tries > 0) {
            $this->_redis->hIncrBy($sessionId, 'wait', -1);
        }

        // This process has the lock, save the pid
        if ($this->_hasLock) {
            $setData = array(
                'pid' => $this->_getPid(),
                'lock' => 1,
            );

            // Save request data in session so if a lock is broken we can know which page it was for debugging
            if (empty($_SERVER['REQUEST_METHOD'])) {
                $setData['req'] = @$_SERVER['SCRIPT_NAME'];
            } else {
                $setData['req'] = $_SERVER['REQUEST_METHOD']." ".@$_SERVER['SERVER_NAME'].@$_SERVER['REQUEST_URI'];
            }
            if ($lock != 1) {
                $this->_log(
                    sprintf(
                        "Successfully broke lock for ID %s after %.5f seconds (%d attempts). Lock: %d\nLast request of "
                            . "broken lock: %s",
                        $sessionId,
                        (microtime(true) - $timeStart),
                        $tries,
                        $lock,
                        $this->_redis->hGet($sessionId, 'req')
                    ),
                    LoggerInterface::INFO
                );
            }
        }

        // Set session data and expiration
        $this->_redis->pipeline();
        if ( ! empty($setData)) {
            $this->_redis->hMSet($sessionId, $setData);
        }
        $this->_redis->expire($sessionId, 3600*6); // Expiration will be set to correct value when session is written
        $this->_redis->exec();

        // Reset flag in case of multiple session read/write operations
        $this->_sessionWritten = false;

        return $sessionData ? (string) $this->_decodeData($sessionData) : '';
    }

    /**
     * Update session
     *
     * @param string $sessionId
     * @param string $sessionData
     * @return boolean
     */
    #[\ReturnTypeWillChange]
    public function write($sessionId, $sessionData)
    {
        if ($this->_sessionWritten || $this->_readOnly) {
            $this->_log(sprintf(($this->_sessionWritten ? "Repeated" : "Read-only") . " session write detected; skipping for ID %s", $sessionId));
            return true;
        }
        $this->_sessionWritten = true;
        $timeStart = microtime(true);

        // Do not overwrite the session if it is locked by another pid
        try {
            if($this->_dbNum) $this->_redis->select($this->_dbNum);  // Prevent conflicts with other connections?

            if ( ! $this->_useLocking
                || ( ! ($pid = $this->_redis->hGet('sess_'.$sessionId, 'pid')) || $pid == $this->_getPid())
            ) {
                $this->_writeRawSession($sessionId, $sessionData, $this->getLifeTime());
                $this->_log(sprintf("Data written to ID %s in %.5f seconds", $sessionId, (microtime(true) - $timeStart)));

            }
            else {
                if ($this->_hasLock) {
                    $this->_log(sprintf("Did not write session for ID %s: another process took the lock.",
                        $sessionId
                    ), LoggerInterface::WARNING);
                } else {
                    $this->_log(sprintf("Did not write session for ID %s: unable to acquire lock.",
                        $sessionId
                    ), LoggerInterface::WARNING);
                }
            }
        }
        catch(\Exception $e) {
            $this->logger->logException($e);
            return false;
        }
        return true;
    }

    /**
     * Destroy session
     *
     * @param string $sessionId
     * @return boolean
     */
    #[\ReturnTypeWillChange]
    public function destroy($sessionId)
    {
        $this->_log(sprintf("Destroying ID %s", $sessionId));
        $this->_redis->pipeline();
        if($this->_dbNum) $this->_redis->select($this->_dbNum);
        $this->_redis->unlink(self::SESSION_PREFIX.$sessionId);
        $this->_redis->exec();
        return true;
    }

    /**
     * Overridden to prevent calling getLifeTime at shutdown
     *
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function close()
    {
        $this->_log("Closing connection");
        if ($this->_redis) $this->_redis->close();
        return true;
    }

    /**
     * Garbage collection
     *
     * @param int $maxLifeTime ignored
     * @return boolean
     */
    #[\ReturnTypeWillChange]
    public function gc($maxLifeTime)
    {
        return true;
    }

    /**
     * Get the number of failed lock attempts
     *
     * @return int
     */
    public function getFailedLockAttempts()
    {
        return $this->failedLockAttempts;
    }

    static public function isBotAgent($userAgent)
    {
        $isBot = !$userAgent || preg_match(self::BOT_REGEX, $userAgent);

        if (is_array(self::$_botCheckCallback) && isset(self::$_botCheckCallback[0]) && self::$_botCheckCallback[1] && method_exists(self::$_botCheckCallback[0], self::$_botCheckCallback[1])) {
            $isBot = (bool) call_user_func_array(self::$_botCheckCallback, [$userAgent, $isBot]);
        }

        return $isBot;
    }

    /**
     * Get lock lifetime
     *
     * @return int|mixed
     */
    protected function getLifeTime()
    {
        if (is_null($this->_lifeTime)) {
            $lifeTime = null;

            // Detect bots by user agent
            $botLifetime = is_null($this->config->getBotLifetime()) ? self::DEFAULT_BOT_LIFETIME : $this->config->getBotLifetime();
            if ($botLifetime) {
                $userAgent = empty($_SERVER['HTTP_USER_AGENT']) ? false : $_SERVER['HTTP_USER_AGENT'];
                if (self::isBotAgent($userAgent)) {
                    $this->_log(sprintf("Bot detected for user agent: %s", $userAgent));
                    $botFirstLifetime = is_null($this->config->getBotFirstLifetime()) ? self::DEFAULT_BOT_FIRST_LIFETIME : $this->config->getBotFirstLifetime();
                    if ($this->_sessionWrites <= 1 && $botFirstLifetime) {
                        $lifeTime = $botFirstLifetime * (1+$this->_sessionWrites);
                    } else {
                        $lifeTime = $botLifetime;
                    }
                }
            }

            // Use different lifetime for first write
            if ($lifeTime === null && $this->_sessionWrites <= 1) {
                $firstLifetime = is_null($this->config->getFirstLifetime()) ? self::DEFAULT_FIRST_LIFETIME : $this->config->getFirstLifetime();
                if ($firstLifetime) {
                    $lifeTime = $firstLifetime * (1+$this->_sessionWrites);
                }
            }

            // Neither bot nor first write
            if ($lifeTime === null) {
                $lifeTime = $this->config->getLifetime();
            }

            $this->_lifeTime = $lifeTime;
            if ($this->_lifeTime < $this->_minLifetime) {
                $this->_lifeTime = $this->_minLifetime;
            }
            if ($this->_lifeTime > $this->_maxLifetime) {
                $this->_lifeTime = $this->_maxLifetime;
            }
        }
        return $this->_lifeTime;
    }

    /**
     * Encode data
     *
     * @param string $data
     * @return string
     */
    protected function _encodeData($data)
    {
        $originalDataSize = strlen($data);
        if ($this->_compressionThreshold > 0 && $this->_compressionLibrary != 'none' && $originalDataSize >= $this->_compressionThreshold) {
            $this->_log(sprintf("Compressing %s bytes with %s", $originalDataSize,$this->_compressionLibrary));
            $timeStart = microtime(true);
            $prefix = ':'.substr($this->_compressionLibrary,0,2).':';
            switch($this->_compressionLibrary) {
                case 'snappy': $data = snappy_compress($data); break;
                case 'lzf':    $data = lzf_compress($data); break;
                case 'lz4':    $data = lz4_compress($data); $prefix = ':l4:'; break;
                case 'gzip':   $data = gzcompress($data, 1); break;
            }
            if($data) {
                $data = $prefix.$data;
                $this->_log(
                    sprintf(
                        "Data compressed by %.1f percent in %.5f seconds",
                        ($originalDataSize == 0 ? 0 : (100 - (strlen($data) / $originalDataSize * 100))),
                        (microtime(true) - $timeStart)
                    )
                );
            } else {
                $this->_log(
                    sprintf("Could not compress session data using %s", $this->_compressionLibrary),
                    LoggerInterface::WARNING
                );
            }
        }
        return $data;
    }

    /**
     * Decode data
     *
     * @param string $data
     * @return string
     */
    protected function _decodeData($data)
    {
        switch (substr($data,0,4)) {
            // asking the data which library it uses allows for transparent changes of libraries
            case ':sn:': $data = snappy_uncompress(substr($data,4)); break;
            case ':lz:': $data = lzf_decompress(substr($data,4)); break;
            case ':l4:': $data = lz4_uncompress(substr($data,4)); break;
            case ':gz:': $data = gzuncompress(substr($data,4)); break;
        }
        return $data;
    }

    /**
     * Write session data to Redis
     *
     * @param $id
     * @param $data
     * @param $lifetime
     * @throws \Exception
     */
    protected function _writeRawSession($id, $data, $lifetime)
    {
        $sessionId = 'sess_' . $id;
        $this->_redis->pipeline()
            ->select($this->_dbNum)
            ->hMSet($sessionId, array(
                'data' => $this->_encodeData($data),
                'lock' => 0, // 0 so that next lock attempt will get 1
            ))
            ->hIncrBy($sessionId, 'writes', 1)
            ->expire($sessionId, min((int)$lifetime, (int)$this->_maxLifetime))
            ->exec();
    }

    /**
     * Get pid
     *
     * @return string
     */
    protected function _getPid()
    {
        return gethostname().'|'.getmypid();
    }

    /**
     * Check if pid exists
     *
     * @param $pid
     * @return bool
     */
    protected function _pidExists($pid)
    {
        list($host,$pid) = explode('|', $pid);
        if (PHP_OS != 'Linux' || $host != gethostname()) {
            return true;
        }
        return @file_exists('/proc/'.$pid);
    }

    /**
     * Get break time, calculated later than other config settings due to requiring session name to be set
     *
     * @return int
     */
    protected function _getBreakAfter()
    {
        // Has break after already been calculated? Only fetch from config once, then reuse variable.
        if (!$this->_breakAfter) {
            // Fetch relevant setting from config using session name
            $this->_breakAfter = (float)($this->config->getBreakAfter() ?: self::DEFAULT_BREAK_AFTER);
            // Use sleep time multiplier so break time is in seconds
            $this->_breakAfter = (int)round((1000000 / self::SLEEP_TIME) * $this->_breakAfter);
        }

        return $this->_breakAfter;
    }
}
