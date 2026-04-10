<?php

namespace App\Contracts;

use App\Tools\ToolResult;

interface ToolInterface
{
    public function name(): string;

    public function description(): string;

    public function inputSchema(): array;

    public function outputSchema(): array;

    public function execute(array $params): ToolResult;
}
