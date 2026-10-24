<?php

declare(strict_types=1);

namespace BancontactPro\Exception;

use RuntimeException;

/** A webhook callback could not be trusted: bad signature or unparseable body. */
final class InvalidCallbackException extends RuntimeException implements BancontactException {}
