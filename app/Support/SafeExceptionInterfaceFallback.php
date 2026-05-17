<?php

namespace Safe\Exceptions;

/**
 * Fallback interface for environments where vendor/thecodingmachine/safe
 * was partially installed and the original interface file is unreadable.
 */
interface SafeExceptionInterface extends \Throwable {}
