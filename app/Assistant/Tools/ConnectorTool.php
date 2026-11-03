<?php

namespace App\Assistant\Tools;

use App\Mcp\Tools\PortalTool;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;

/**
 * A read tool of the Claude connector (App\Mcp\Tools\*) for the assistant:
 * the same name, description, input schema and handle(), run for the
 * signed-in account alone (PortalTool::readFor()). So the assistant reads
 * exactly what the connector reads for that account, through the same
 * visibility code, and nothing is re-implemented here.
 */
final class ConnectorTool implements AssistantTool
{
    /**
     * @param  class-string<PortalTool>  $class
     */
    public function __construct(private readonly string $class) {}

    public function name(): string
    {
        return $this->tool()->name();
    }

    public function description(): string
    {
        return $this->tool()->description();
    }

    public function inputSchema(): array
    {
        return $this->tool()->toArray()['inputSchema'];
    }

    public function strict(): bool
    {
        return false;
    }

    public function availableTo(User $user): bool
    {
        return true;
    }

    public function run(User $user, array $input): ToolOutcome
    {
        try {
            $response = $this->tool()->readFor(collect([$user]), $input, $this->rows());
        } catch (ValidationException $invalid) {
            return ToolOutcome::error(collect($invalid->errors())->flatten()->first() ?? 'Datos de entrada no válidos.');
        }

        if ($response instanceof Response) {
            return $response->isError()
                ? ToolOutcome::error((string) $response->content())
                : ToolOutcome::text((string) $response->content());
        }

        $structured = $response->getStructuredContent();

        return $structured === null
            ? ToolOutcome::text($response->responses()->map(fn (Response $part) => (string) $part->content())->implode("\n"))
            : ToolOutcome::data($structured);
    }

    /**
     * The assistant's row cap of this tool (`assistant.tool_limits`), if any.
     */
    private function rows(): ?int
    {
        $rows = config('assistant.tool_limits.'.$this->name());

        return is_numeric($rows) ? (int) $rows : null;
    }

    private function tool(): PortalTool
    {
        return app($this->class);
    }
}
