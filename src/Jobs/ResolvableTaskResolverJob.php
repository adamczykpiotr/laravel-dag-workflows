<?php

namespace AdamczykPiotr\DagWorkflows\Jobs;

use AdamczykPiotr\DagWorkflows\Definitions\Task;
use AdamczykPiotr\DagWorkflows\Models\Workflow;
use AdamczykPiotr\DagWorkflows\Services\WorkflowDefinitionParser;
use AdamczykPiotr\DagWorkflows\Services\WorkflowRepository;
use AdamczykPiotr\DagWorkflows\Traits\HasWorkflowTracking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Laravel\SerializableClosure\SerializableClosure;
use Throwable;

class ResolvableTaskResolverJob implements ShouldQueue {

    use HasWorkflowTracking, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    /**
     * @param string $name
     * @param string|array<int, string> $dependsOn
     * @param SerializableClosure $itemProvider
     * @param SerializableClosure $jobProvider
     */
    public function __construct(
        protected string $name,
        protected array $dependsOn,
        protected SerializableClosure $itemProvider,
        protected SerializableClosure $jobProvider,
    ) {
    }


    /**
     * @template TEntry
     * @param WorkflowDefinitionParser $definitionParser
     * @param WorkflowRepository $workflowRepository
     * @return void
     * @throws Throwable
     */
    public function handle(
        WorkflowDefinitionParser $definitionParser,
        WorkflowRepository $workflowRepository
    ): void {
        /** @var callable():iterable<TEntry> $provider */
        $provider = $this->itemProvider->getClosure();
        $items = $provider();

        /** @var callable(TEntry): array $jobProvider */
        $jobProvider = $this->jobProvider->getClosure();

        /** @var Collection<int, Task> $definitions */
        $definitions = collect($items)->map(function($item, string|int $key) use ($jobProvider) {
            return new Task(
                name: "{$this->name}:{$key}",
                jobs: $jobProvider($item),
                dependsOn: $this->dependsOn,
            );
        });

        $tasks = $definitionParser->parseTasksFromResolvable($definitions);

        $workflow = Workflow::findOrFail($this->getWorkflowId());
        $workflowRepository->appendTasks($workflow, $tasks);
    }
}
