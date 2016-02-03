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
namespace Cm\RedisSession\Handler;

interface ConfigInterface
{
    /**
     * Get log level
     *
     * @return int
     */
    public function getLogLevel();

    /**
     * Get host, can be absolute path if using unix socket
     *
     * @return string
     */
    public function getHost();

    /**
     * Get port
     *
     * @return int
     */
    public function getPort();

    /**
     * Get database number
     *
     * @return int
     */
    public function getDatabase();

    /**
     * Get password
     *
     * @return string
     */
    public function getPassword();

    /**
     * Get connection timeout
     *
     * @return float
     */
    public function getTimeout();

    /**
     * Get unique string for persistent connections, if empty persistent connection is not used
     *
     * @return string
     */
    public function getPersistentIdentifier();

    /**
     * Get compression threshold
     *
     * @return int
     */
    public function getCompressionThreshold();

    /**
     * Get compression library (gzip, lzf, lz4 or snappy)
     *
     * @return string
     */
    public function getCompressionLibrary();

    /**
     * Maximum number of processes that can wait for a lock on one session
     *
     * @return int
     */
    public function getMaxConcurrency();

    /**
     * Get the normal session lifetime
     *
     * @return int
     */
    public function getLifetime();

    /**
     * Get the maximum session lifetime
     *
     * @return int
     */
    public function getMaxLifetime();

    /**
     * Get the minimum session lifetime
     *
     * @return int
     */
    public function getMinLifetime();

    /**
     * Disable session locking entirely
     *
     * @return bool
     */
    public function getDisableLocking();

    /**
     * Get lifetime of session for bots on subsequent writes, 0 to disable
     *
     * @return int
     */
    public function getBotLifetime();

    /**
     * Get lifetime of session for bots on the first write, 0 to disable
     *
     * @return int
     */
    public function getBotFirstLifetime();

    /**
     * Get lifetime of session for non-bots on the first write, 0 to disable
     *
     * @return int
     */
    public function getFirstLifetime();

    /**
     * Get number of seconds to wait before trying to break the lock
     *
     * @return int
     */
    public function getBreakAfter();
}
