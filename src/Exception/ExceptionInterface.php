<?php

declare(strict_types=1);

namespace Webware\FileWriter\Exception;

use Throwable;

/**
 * Marks every exception this package raises, so a caller can catch the package's
 * failures without catching unrelated ones.
 *
 * Extends Throwable so the marker cannot be put on a class that is not an
 * exception: PHP refuses to load such a class, naming the offence.
 *
 * @api
 */
interface ExceptionInterface extends Throwable {}
