<?php

declare(strict_types=1);

namespace Webware\FileWriter\Exception;

use InvalidArgumentException as BaseInvalidArgumentException;

/**
 * @api
 */
final class InvalidArgumentException extends BaseInvalidArgumentException implements ExceptionInterface {}
