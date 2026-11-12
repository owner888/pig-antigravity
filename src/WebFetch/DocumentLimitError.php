<?php

declare(strict_types=1);

namespace PigAntigravity\WebFetch;

use RuntimeException;

/** "The page is beyond what the converter will take on; the source itself is fine." */
final class DocumentLimitError extends RuntimeException
{
}
