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

interface LoggerInterface
{
    /**
     * Emergency: system is unusable
     */
    const EMERGENCY     = 0;

    /**
     * Alert: action must be taken immediately
     */
    const ALERT         = 1;

    /**
     * Critical: critical conditions
     */
    const CRITICAL      = 2;

    /**
     * Error: error conditions
     */
    const ERROR         = 3;

    /**
     * Warning: warning conditions
     */
    const WARNING       = 4;

    /**
     * Notice: normal but significant condition
     */
    const NOTICE        = 5;

    /**
     * Informational: informational messages
     */
    const INFO          = 6;

    /**
     * Debug: debug messages
     */
    const DEBUG         = 7;

    /**
     * Set log level
     *
     * @param int $level
     * @return void
     */
    public function setLogLevel($level);

    /**
     * Log message
     *
     * @param string $message
     * @param string $level
     * @param string $file
     * @return void
     */
    public function log($message, $level);

    /**
     * Log exception
     *
     * @param \Exception $e
     * @return void
     */
    public function logException(\Exception $e);
}
