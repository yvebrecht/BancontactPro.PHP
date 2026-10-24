<?php

declare(strict_types=1);

namespace BancontactPro\Exception;

use Throwable;

/** Marker for every exception this library throws on purpose (network failures from the PSR-18 client pass through). */
interface BancontactException extends Throwable {}
