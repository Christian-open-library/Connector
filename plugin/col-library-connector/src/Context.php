<?php
/**
 * Per-request context handed to the tools, and the exception Tools::call throws for unknown tools.
 * Shared interface between the protocol layer (Http/Mcp) and the tools layer; see PLUGIN-SPEC.md.
 */

namespace ColLibrary;

defined('ABSPATH') || exit;

final class Context
{
    public function __construct(
        public readonly Config $config,
        public readonly RateLimit $limits,
        public readonly Log $log,
        public readonly float $deadline,   // microtime(true) by which the tool must finish
    ) {
    }

    /** Seconds left before the deadline (negative once it has passed). */
    public function remaining(): float
    {
        return $this->deadline - microtime(true);
    }
}

/** Thrown by Tools::call for a tool name it does not know; Mcp turns it into JSON-RPC -32602. */
final class UnknownToolException extends \Exception
{
}
