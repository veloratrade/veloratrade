<?php

declare(strict_types=1);

namespace Velora\Support;

/** Raised when the AI/translation layer cannot serve a request (never fatal to support flows). */
class SupportAIException extends \RuntimeException
{
}
